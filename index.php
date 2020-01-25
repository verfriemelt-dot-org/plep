<?php

    class Console {

        const STYLE_NONE      = 0;
        const STYLE_BLACK     = 30;
        const STYLE_RED       = 31;
        const STYLE_GREEN     = 32;
        const STYLE_YELLOW    = 33;
        const STYLE_BLUE      = 34;
        const STYLE_PURPLE    = 35;
        const STYLE_CYAN      = 36;
        const STYLE_WHITE     = 37;
        const STYLE_REGULAR   = "0";
        const STYLE_BOLD      = "1";
        const STYLE_UNDERLINE = "4";

        protected $currentFgColor   = SELF::STYLE_NONE;
        protected $currentBgColor   = SELF::STYLE_NONE;
        protected $currentFontStyle = SELF::STYLE_REGULAR;
        protected $selectedStream;
        protected $stdout           = STDOUT;
        protected $stderr           = STDERR;
        protected $linePrefixFunc;
        protected $hadLineOutput    = false;
        protected $dimensions       = null;
        protected $inTerminal = false;
        protected $colorSupported = null;
        protected $forceColor     = false;

        /**
         *
         * @var ParameterBag
         */
        protected $argv;

        public static function getInstance(): Console {
            return new static( isset( $_SERVER["argv"] ) ? $_SERVER["argv"] : [] );
        }

        public function __construct() {

            $this->selectedStream = &$this->stdout;
            $this->argv           = new ParameterBag( $_SERVER["argv"] ?? [] );

            $this->inTerminal = isset( $_SERVER['TERM'] );
        }

        public static function isCli(): bool {
            return php_sapi_name() === "cli";
        }

        public function getArgv(): ParameterBag {
            return $this->argv;
        }

        public function getArgvAsString(): string {

            // omit first element
            return implode( " ", $this->argv->except( [ 0 ] ) );
        }

        public function setPrefixCallback( callable $func ): Console {
            $this->linePrefixFunc = $func;
            return $this;
        }

        public function toSTDOUT(): Console {
            $this->selectedStream = &$this->stdout;
            return $this;
        }

        public function toSTDERR(): Console {
            $this->selectedStream = &$this->stderr;
            return $this;
        }

        public function write( $text, $color = null ): Console {

            if ( $color !== null ) {
                $this->setForegroundColor( $color );
            }

            if ( $this->linePrefixFunc !== null && $this->hadLineOutput !== true ) {
                fwrite( $this->selectedStream, ($this->linePrefixFunc)() );
                $this->hadLineOutput = true;
            }

            fwrite( $this->selectedStream, $text );

            if ( $color !== null ) {
                $this->setForegroundColor( static::STYLE_NONE );
            }

            return $this;
        }

        public function writeLn( $text, $color = null ): Console {
            return $this->write( $text, $color )->eol();
        }

        public function cr(): Console {
            $this->write( "\r" );
            return $this;
        }

        public function eol(): Console {
            $this->write( PHP_EOL );
            $this->hadLineOutput = false;
            return $this;
        }

        public function writePadded( $text, $padding = 4, $paddingChar = " ", $color = null ): Console {
            $this->write( str_repeat( $paddingChar, $padding ) );
            $this->write( $text, $color );

            return $this;
        }

        // this is blocking
        public function read() {
            return fgets( STDIN );
        }

        protected function setOutputStyling() {

            if ( $this->colorSupported === null ) {
                $this->colorSupported = $this->supportsColor();
            }

            if ( !$this->colorSupported ) {
                return;
            }

            $this->write( "\e[{$this->currentFontStyle};{$this->currentFgColor}m" );

            if ( $this->currentBgColor !== self::STYLE_NONE ) {
                $this->write( "\e[{$this->currentBgColor}m" );
            }
        }

        public function setFontFeature( int $style ): Console {
            $this->currentFontStyle = $style;
            $this->setOutputStyling();
            return $this;
        }

        public function setBackgroundColor( int $color ): Console {
            $this->currentBgColor = $color + 10;
            $this->setOutputStyling();
            return $this;
        }

        public function setForegroundColor( int $color ): Console {
            $this->currentFgColor = $color;
            $this->setOutputStyling();
            return $this;
        }

        public function cls(): Console {
            $this->write( "\e[2J" );
            return $this;
        }

        public function up( int $amount = 1 ): Console {
            $this->write( "\e[{$amount}A" );
            return $this;
        }

        public function down( int $amount = 1 ): Console {
            $this->write( "\e[{$amount}B" );
            return $this;
        }

        public function right( int $amount = 1 ): Console {
            $this->write( "\e[{$amount}C" );
            return $this;
        }

        public function left( int $amount = 1 ): Console {
            $this->write( "\e[{$amount}D" );
            return $this;
        }

        /**
         * stores cursor position
         * @return \Wrapped\_\Cli\Console
         */
        public function push(): Console {
            $this->write( "\e[s" );
            return $this;
        }

        /**
         * restores cursor position
         * @return \Wrapped\_\Cli\Console
         */
        public function pop(): Console {
            $this->write( "\e[u" );
            return $this;
        }

        public function jump( int $x = 0, int $y = 0 ): Console {
            $this->write( "\e[{$y};{$x}f" );
            return $this;
        }

        /**
         * reset all style features
         */
        public function __destruct() {
            if ( $this->currentFgColor !== self::STYLE_NONE || $this->currentBgColor !== self::STYLE_NONE ) {
                $this->write( "\e[0m" );
            }
        }

        public function getWidth(): ?int {

            if ( $this->dimensions === null ) {
                $this->updateDimensions();
            }

            return $this->dimensions[0] ?? null;
        }

        public function getHeight(): ?int {

            if ( $this->dimensions === null ) {
                $this->updateDimensions();
            }

            return $this->dimensions[1] ?? null;
        }

        public function updateDimensions(): bool {

            $descriptorspec = [
                1 => [ 'pipe', 'w' ],
                2 => [ 'pipe', 'w' ],
            ];

            $process = proc_open( 'stty -a | grep columns', $descriptorspec, $pipes, null, null, [ 'suppress_errors' => true ] );

            if ( is_resource( $process ) ) {
                $info = stream_get_contents( $pipes[1] );
                fclose( $pipes[1] );
                fclose( $pipes[2] );
                proc_close( $process );
            } else {
                return false;
            }

            if ( preg_match( '/rows.(\d+);.columns.(\d+);/i', $info, $matches ) ) {
                // extract [w, h] from "rows h; columns w;"
                $this->dimensions[0] = (int) $matches[2];
                $this->dimensions[1] = (int) $matches[1];
            } elseif ( preg_match( '/;.(\d+).rows;.(\d+).columns/i', $info, $matches ) ) {
                // extract [w, h] from "; h rows; w columns"
                $this->dimensions[0] = (int) $matches[2];
                $this->dimensions[1] = (int) $matches[1];
            } else {
                return false;
            }

            return true;
        }

        public function forceColorOutput( $bool = true ) {
            $this->forceColor = $bool;
            return $this;
        }

        public function supportsColor(): bool {

            if ( $this->forceColor ) {
                return true;
            }

            if ( !$this->inTerminal ) {
                return false;
            }

            $descriptorspec = [
                1 => [ 'pipe', 'w' ],
                2 => [ 'pipe', 'w' ],
            ];

            $process = proc_open( 'tput colors', $descriptorspec, $pipes, null, null, [ 'suppress_errors' => true ] );

            if ( is_resource( $process ) ) {
                $info = stream_get_contents( $pipes[1] );
                fclose( $pipes[1] );
                fclose( $pipes[2] );
                proc_close( $process );
            } else {
                return false;
            }

            return (int) $info > 1;
        }

    }
    

        class ParameterBag
        implements Countable, IteratorAggregate {

            private $parameters = [], $raw = null;

            public function __construct( array $parameters ) {
                $this->parameters = $parameters;
            }

            public function count() {
                return count( $this->parameters );
            }

            /**
             *
             * @return ArrayIterator
             */
            public function getIterator() {
                return new ArrayIterator( $this->parameters );
            }

            public function hasNot( $param ): bool {
                return !$this->has( $param );
            }

            public function has( $key ): bool {

                if ( !is_array( $key ) ) {
                    return isset( $this->parameters[$key] );
                }

                foreach ( $key as $name ) {
                    if ( !isset( $this->parameters[$name] ) ) {
                        return false;
                    }
                }

                return true;
            }

            public function get( string $key, $default = null ) {

                if ( !$this->has( $key ) ) {
                    return $default;
                }

                return $this->parameters[$key];
            }

            public function is( $key, $value ) {
                return $this->get( $key ) == $value;
            }

            public function isNot( $key, $value ) {
                return $this->get( $key ) != $value;
            }

            public function all() {
                return $this->parameters;
            }

            public function first() {
                reset( $this->parameters );
                return current( $this->parameters );
            }

            public function last() {
                end( $this->parameters );
                return current( $this->parameters );
            }

            public function except( array $filter = [] ) {

                $return = [];

                foreach ( $this->all() as $key => $value ) {
                    if ( !in_array( $key, $filter ) ) {
                        $return[$key] = $value;
                    }
                }

                return $return;
            }

            /**
             * overrides key in the given bag
             * @param type $key
             * @param type $value
             * @return ParameterBag
             */
            public function override( $key, $value ) {
                $this->parameters[$key] = $value;
                return $this;
            }

            public function setRawData( $content ): ParameterBag {
                $this->raw = $content;
                return $this;
            }

            public function getRawData() {
                return $this->raw;
            }

        }
    
    class KeyInput {
      
        protected $registeredKeys = [];
        public $lastKey = null;
      
        public function registerKey( string $key, $callback ): KeyInput {
            
            $this->registeredKeys[$key][] = $callback;
            return $this;
        }
      
        public function consume( $stream ) {
          
            $keybuffer = [];
            $key = null;
            
            for( $i = 0; $i < 6; $i++) {
              $keybuffer[$i] = ord(fgetc($stream));
            }
            
            if ( !$keybuffer[0] ) {
              return;
            }
            
            // normal keys
            if ( $keybuffer[0] >= 48 && $keybuffer[0] <= 122 ) {
              
              $key = chr($keybuffer[0]);
              
            // alt hit
            } elseif ( 
                 $keybuffer[0] == 27 
              && $keybuffer[1] >= 48 && $keybuffer[1] <= 122
              && $keybuffer[2] == null
            ) {
              
              $key = 'alt-' . chr( $keybuffer[1]);
              
            // f1-f4
            } elseif (
                 $keybuffer[0] == 27 
              && $keybuffer[1] == 79
              && $keybuffer[2] >= 80 && $keybuffer[2] <= 84
              && $keybuffer[3] == null 
            ) {
                switch( $keybuffer[2] ) {
                  case 80: $key = 'F1'; break;
                  case 81: $key = 'F2'; break;
                  case 82: $key = 'F3'; break;
                  case 83: $key = 'F4'; break;
                }
            }
            
            if ( $key === null )  {
              return false;
            }
            
            $this->lastKey = $key;
             
            if ( isset( $this->registeredKeys[ $key ] ) ) {
                foreach( $this->registeredKeys[ $key ] as $callback ) {
                    $callback();
                }
            }
            
            return true;
        }
    }
    
    class Debugger {
      
        private $pg;
        
        public $stack  = [];
        public $vars   = [];
        public $source = [];
        private $breakpoints = [];
        
        private $channel = null;
        
        private $async_result;
        
        private $initialized = false;
        private $attached    = false;
        public $currentFrame = null;
      
        public function __construct( string $connection) {
            $this->pg = \pg_connect( $connection );
            pg_set_error_verbosity( $this->pg, \PGSQL_ERRORS_DEFAULT );
        }
        
        public function isInitialized(): bool {
          return $this->initialized;
        }
        
        public function isAttached(): bool {
          return $this->attached;
        }
        
        public function init() {
          
            // setup channel
            
            $this->channel = pg_fetch_assoc(pg_query('select pldbg_create_listener()'))['pldbg_create_listener'];
            
            // oid hardcoded to given functions
            $this->addGlobalBreakPoint( 44556 );
        }
        
        public function incrementStackFrame() {
            if ( $this->currentFrame === null || $this->currentFrame + 1 >= count( $this->stack ) ) {
              // nope
              return;
            }
            
            $this->currentFrame++;

            pg_query("select pldbg_select_frame({$this->channel},{$this->currentFrame})");

            $this->updateVars();
            $this->updateSource();
        }
        
        public function decrementStackFrame() {
          
            if ( $this->currentFrame === null || $this->currentFrame - 1 < 0 ) {
              // nope
              return;
            }
            
            $this->currentFrame--;
            
            pg_query("select pldbg_select_frame({$this->channel},{$this->currentFrame})");
            
            $this->updateVars();
            $this->updateSource();
        }
        
        public function stepInto() {
          
            pg_query("select pldbg_step_into({$this->channel})");
            
            $this->updateStack();
            $this->updateSource();
            $this->updateVars();
        }
        
        public function updateSource() {
            $this->source = explode("\n",pg_fetch_assoc(pg_query("select pldbg_get_source as src  from pldbg_get_source({$this->channel}," . $this->stack[$this->currentFrame]['func'] . ")"))['src']);
        }
        
        public function updateStack() {
            $this->stack = pg_fetch_all(pg_query("select * from pldbg_get_stack({$this->channel})")) ?? [];
            
            $this->currentFrame = 0;
        }
        
        public function updateVars() {
            $this->vars   = pg_fetch_all(pg_query("select *, pg_catalog.format_type(dtype, NULL) as dtype from pldbg_get_variables({$this->channel})"));
        }
        
        public function addGlobalBreakPoint( int $oid , int $line = null ) {
            pg_query("select pldbg_set_global_breakpoint({$this->channel},{$oid},null,null)");
        }
        
        public function waitForConnection() {
            $this->async_result = pg_send_query($this->pg, "select pldbg_wait_for_target({$this->channel})");
        }
        
        public function checkForConnection() {
          
            if ( pg_connection_busy( $this->pg ) ) {
                return false;
            }
            
            // clear results from connection
            pg_get_result( $this->pg );
            
            $this->attached = true;
            return true;
        }
        
        
        
      
    }

    // setup application
    $cli = new Console();
    $cli->cls();
    
    $connectionString = 'host=localhost port=6666 dbname=docker user=docker password=docker';

    // todo
    // save old settings with
    // stty -g < /dev/tty
    // restore later with stty $old < /dev/tty
    system('stty -echo -icanon min 1 time 0 < /dev/tty');
    $stdin = fopen('php://stdin', 'r');
    stream_set_blocking($stdin, 0);

    $targetFps = 30;

    pcntl_async_signals(true);
    
    pcntl_signal(
        SIGWINCH,
        function () use ($cli): void {
            $cli->updateDimensions();
            $cli->cls();
        }
    );
    
    $attached = false;
    $initialized = false;
    
    $debugger = new Debugger( $connectionString );
    $input = new KeyInput();
    
    $input->registerKey('q', function () { exit; });
    
    $input->registerKey('F1', [$debugger,'decrementStackFrame'] );
    $input->registerKey('F2', [$debugger,'incrementStackFrame'] );
    $input->registerKey('F4', [$debugger,'stepInto'] );
    
    $debugger->init();
    $debugger->waitForConnection();
    $displayUpdate = false;

    while( true ) {

        $start = microtime(1);
        $displayUpdate = $input->consume( $stdin );
        
        // if not attached check for connection
        if ( !$debugger->isAttached() && $debugger->checkForConnection() ) {
            $displayUpdate = true;
            
            $debugger->updateStack();
            $debugger->updateSource( 44556 );
            $debugger->updateVars();
        }
        
        if ( $debugger->isAttached() ) {
          $cli->jump(0,1);
          $cli->write( 'connected');  
        } else {
          $cli->jump(0,0);
          $cli->write( 'waiting');  
        }
        
        
        if ( $displayUpdate ) {
          
            $cli->cls();
          
            $result = $debugger->stack[0] ?? [];
            $source = $debugger->source;
            $vars = $debugger->vars;
          

            // $cli->cls();
            $cli->jump( 0,3 );
            $cli->write( "Line: " .  ($result['linenumber'] ?? '') );
            
            if ( $source ) {
              
              $offset = 5;
              
              for ( $line = 0; $line < $cli->getHeight() - $offset && $line < count($source); $line++ ) {

                  $cli->jump(0, $line + $offset );
                  
                  if ( isset($result['linenumber']) && ( $line + 1 ) == $result['linenumber'] ) {
                    $cli->write( str_pad( $line + 1, 4,' ', \STR_PAD_LEFT ) . ": " . $source[$line], Console::STYLE_BLUE);
                  } else {
                    $cli->write( str_pad( $line + 1, 4,' ', \STR_PAD_LEFT ) . ": " . $source[$line]);
                  }
                  

              }
              
              // stack
              

              
              foreach( $debugger->stack  as $l ) {
                
                $cli->jump( $cli->getWidth() - 100, $offset );
                
                if ( $l['level'] == $debugger->currentFrame ) {
                    $cli->write( implode($l,"\t"), Console::STYLE_BLUE );
                } else {
                    $cli->write( implode($l,"\t") );  
                }
                
                
                $offset++;
              }
              
              $offset++;
              $offset++;
              // vars
              
              $cli->jump( $cli->getWidth() - 100 , $offset  - 1);
              $cli->write( str_pad ( 'name', 20) );
              $cli->write( str_pad ( 'value', 30) );
              $cli->write( str_pad ( 'dtype', 30) );
              $cli->write( str_pad ( 'class', 6 ));
              $cli->write( str_pad ( 'line', 6 ));
              $cli->write( str_pad ( 'U', 3 ));
              $cli->write( str_pad ( 'C', 3 ));
              $cli->write( str_pad ( 'N', 3 ));
              
              foreach( $vars as $var ) {
                  
                $cli->jump( $cli->getWidth() - 100, $offset );
                $cli->write( str_pad ( $var['name'], 20) );
                $cli->write( str_pad ( $var['value'], 30) );
                $cli->write( str_pad ( $var['dtype'], 30) );
                $cli->write( str_pad ( $var['varclass'], 6 ));
                $cli->write( str_pad ( $var['linenumber'], 6 ));
                $cli->write( str_pad ( $var['isunique'], 3 ));
                $cli->write( str_pad ( $var['isconst'], 3 ));
                $cli->write( str_pad ( $var['isnotnull'], 3 ));
                $offset++;
              }
              

            }
          
        }



        $frametime = ( microtime(1) - $start ) * 1000 ;

        if ( ( 1 / $targetFps * 1000 ) > $frametime ) {
            usleep( 
                ( 
                    1 / $targetFps * 1000 // frames per ms
                  - $frametime
                ) * 1000  // µs
            );
        }

        $frametime = ( microtime(1) - $start ) * 1000 ;

        $cli->jump( $cli->getWidth() - strlen('[alt-q] fps: 30.00') ,$cli->getHeight() );
        $cli->write( str_pad("[{$input->lastKey}]",7) .  '  fps: '. number_format( (1 / $frametime)*1000 , 2 ) );

    }
