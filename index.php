<?php



    // hardcoded for now
    $connectionString = 'host=localhost port=6666 dbname=docker user=docker password=docker';

    // lazy ass autoloader
    spl_autoload_register( function ( $class ) {

        $possiblePaths = [
            __DIR__ . "/_/" . str_replace( "\\", "/", $class ) . ".php", // app data
        ];

        foreach ( $possiblePaths as $path ) {
            if ( file_exists( $path ) ) {
                return require_once $path;
            }
        }
    } );

    // setup application
    $earlyConsole = new Console();
    $earlyConsole->setPrefixCallback( function () {
        return "[" . (new DateTime )->format( "H:i:s.u" ) . "] ";
    });

    $earlyConsole->writeLn('setting up tty');

    // todo
    // save old settings with
    // stty -g < /dev/tty
    // restore later with stty $old < /dev/tty
    system('stty -echo -icanon min 1 time 0 < /dev/tty');
    $stdin = fopen('php://stdin', 'r');
    stream_set_blocking($stdin, 0);

    $earlyConsole->writeLn('setting application');

    $targetFps = 30;
    $attached = false;
    $initialized = false;

    $cli = new Console();
    $debugger = new Debugger();
    $input = new KeyInput();

    $earlyConsole->writeLn('connecting to database');

    $pg = \pg_connect( $connectionString );
    \pg_set_error_verbosity( $pg, \PGSQL_ERRORS_DEFAULT );

    $debugger->setConnectionHandle( $pg );

    $earlyConsole->writeLn('setting up shortcuts');

    $input->registerKey('q', function () { exit; });

    // for the stackframe we cannot use the convient function, because its resets the stack info
    $input->registerKey(
        'F1',
        [$debugger,'decrementStackFrame'], [$debugger,'updateVars'], [$debugger,'updateSource']
    );
    $input->registerKey(
        'F2',
        [$debugger,'incrementStackFrame'], [$debugger,'updateVars'], [$debugger,'updateSource']
    );

    // step into
    $input->registerKey('F4', [$debugger,'stepInto'], [$debugger,'refresh']  );

    // step over
    $input->registerKey('F5', [$debugger,'stepOver'], [$debugger,'refresh']  );

    // step over
    $input->registerKey('F8', [$debugger,'continue'], [$debugger,'refresh']  );

    $input->registerKey('F10', [$debugger,'abort'] );

    // cursor
    $input->registerKey('down', [$debugger,'nextLine'] );
    $input->registerKey('up', [$debugger,'previousLine'] );

    // readmode
    $input->registerKey(':', function () use ($input) {
        $input->readMode(function ( $buffer ) {

            foreach( $buffer as $input ) {

                switch ($input) {
                    case 'space': echo ' '; break;
                    default: echo $input;
                }

            }
            die();
            // die(implode('', $buffer));
        });
    });

    $earlyConsole->writeLn('error handler');

    // lazy ass exception handling
    set_exception_handler( function ( $e ) {

        (new Console())->cls();

        echo $e->getTraceAsString() . PHP_EOL . PHP_EOL . PHP_EOL;
        print_r( $e );
        die();
    } );

    set_error_handler( function ( $errno, $errstr, $errfile, $errline ) {
        throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
    } );

    $earlyConsole->writeLn('registering resize handler');

    $cli->updateDimensions();
    $cli->cls();

    $sourceFrame = new ConsoleFrame( $cli );
    $sourceFrame->setPosition( 0, 2 );
    $sourceFrame->setDimension( $cli->getWidth() - 100 , $cli->getHeight() - 2 );

    $stackFrame = new ConsoleFrame( $cli );
    $stackFrame->setPosition( $cli->getWidth() - 100, 2 );
    $stackFrame->setDimension( $cli->getWidth() - 100 , $cli->getHeight() - 2 );

    pcntl_async_signals(true);

    pcntl_signal(
        SIGWINCH,
        function () use ($cli, &$forceRedraw, $stackFrame, $sourceFrame): void {
            $cli->updateDimensions();
            $forceRedraw = true;

            $sourceFrame->setDimension( $cli->getWidth() - 100 , $cli->getHeight() - 2 );

            $stackFrame->setPosition( $cli->getWidth() - 100, 2 );
            $stackFrame->setDimension( $cli->getWidth() - 100 , $cli->getHeight() - 2 );
        }
    );


    $debugger->init();
    $debugger->waitForConnection();

    $displayUpdate = false;
    $forceRedraw = false;

    while( true ) {

        $start = microtime(1);
        $displayUpdate = $input->consume( $stdin );

        // if not attached check for connection
        if ( !$debugger->isAttached() && $debugger->checkForConnection() ) {
            $displayUpdate = true;

            $debugger->updateStack();
            $debugger->updateSource();
            $debugger->updateVars();
        }

        if ( !$debugger->isAttached() ) {
          $cli->jump(0,0);
          $cli->write( 'waiting');
        }


        if ( $displayUpdate || $forceRedraw ) {

            $forceRedraw = false;

            $cli->cls();

            $result = $debugger->stack[ $debugger->currentFrame ] ?? [];
            $source = $debugger->source;
            $vars = $debugger->vars;


            if ( $source ) {

              // sourcecode
              $sourceFrame->clearBuffer();
              $sourceFrame->addToBuffer(
                  str_repeat(' ', 7) . $debugger->stack[ $debugger->currentFrame ]['targetname'],
                  Console::STYLE_BOLD
              );
              $sourceFrame->addToBuffer('');

              for ( $line = 0; $line < count( $source ); $line++ ) {

                  $sourceFrame->addToBuffer(
                      str_pad( $line + 1, 4,' ', \STR_PAD_LEFT ) . ": " . $source[$line],
                      ( $line + 1 ) == $result['linenumber'] ? Console::STYLE_BLUE : Console::STYLE_NONE
                  );
              }

              $sourceFrame->render();

              // stack
              $stackFrame->clearBuffer();

              foreach( $debugger->stack  as $l ) {

                $text = "{$l['level']} » {$l['targetname']}:{$l['func']} » {$l['args']}";
                $stackFrame->addToBuffer(
                    $text ,
                    ($l['level'] == $debugger->currentFrame ) ? Console::STYLE_BLUE : Console::STYLE_NONE
                );
              }

              $stackFrame->addToBuffer('');
              $stackFrame->addToBuffer('');

              // vars
              $stackFrame->addToBuffer(
                  str_pad ( 'name', 20)  .
                  str_pad ( 'value', 30)  .
                  str_pad ( 'dtype', 30)  .
                  str_pad ( 'class', 6 ) .
                  str_pad ( 'line', 6 ) .
                  str_pad ( 'U', 3 ) .
                  str_pad ( 'C', 3 ) .
                  str_pad ( 'N', 3 ),
                  Console::STYLE_BOLD
              );

              foreach( $vars as $var ) {

                $stackFrame->addToBuffer(

                    str_pad ( $var['name'], 20)  .
                    str_pad ( $var['value'], 30)  .
                    str_pad ( $var['dtype'], 30)  .
                    str_pad ( $var['varclass'], 6 ) .
                    str_pad ( $var['linenumber'], 6 ) .
                    str_pad ( $var['isunique'], 3 ) .
                    str_pad ( $var['isconst'], 3 ) .
                    str_pad ( $var['isnotnull'], 3 )
                );
              }

              $stackFrame->render();
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


        $cli->jump( 0 ,$cli->getHeight() );
        $cli->write( implode('',$input->readBuffer));

        $cli->jump( $cli->getWidth() - strlen('[pgdown] fps: 30.00') ,$cli->getHeight() );
        $cli->write( str_pad("[{$input->lastKey}]",8) .  '  fps: '. number_format( (1 / $frametime)*1000 , 2 ) );

    }
