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
    } );

    $earlyConsole->writeLn( 'setting up tty' );

    // todo
    // save old settings with
    // stty -g < /dev/tty
    // restore later with stty $old < /dev/tty
    system( 'stty -echo -icanon min 1 time 0 < /dev/tty' );
    $stdin = fopen( 'php://stdin', 'r' );
    stream_set_blocking( $stdin, 0 );

    $earlyConsole->writeLn( 'setting application' );

    $targetFps   = 30;
    $attached    = false;
    $initialized = false;

    $cli      = new Console();
    $debugger = new Debugger();
    $input    = new KeyInput();

    $earlyConsole->writeLn( 'connecting to database' );

    $pg = \pg_connect( $connectionString );
    \pg_set_error_verbosity( $pg, \PGSQL_ERRORS_DEFAULT );

    $debugger->setConnectionHandle( $pg );

    $earlyConsole->writeLn( 'setting up shortcuts' );

    $input->registerKey( 'q', function () {
        exit;
    } );

    // for the stackframe we cannot use the convient function, because its resets the stack info
    $input->registerKey(
        'F1',
        [ $debugger, 'decrementStackFrame' ], [ $debugger, 'updateVars' ], [ $debugger, 'updateSource' ]
    );
    $input->registerKey(
        'F2',
        [ $debugger, 'incrementStackFrame' ], [ $debugger, 'updateVars' ], [ $debugger, 'updateSource' ]
    );

    // step into
    $input->registerKey( 'F4', [ $debugger, 'stepInto' ], [ $debugger, 'refresh' ] );

    // step over
    $input->registerKey( 'F5', [ $debugger, 'stepOver' ], [ $debugger, 'refresh' ] );

    // step over
    $input->registerKey( 'F8', [ $debugger, 'continue' ], [ $debugger, 'refresh' ] );



    // readmode
    $input->registerKey( ':', function () use ( $input ) {
        $input->readMode( function ( $buffer ) {

            foreach ( $buffer as $input ) {

                switch ( $input ) {
                    case 'space': echo ' ';
                        break;
                    default: echo $input;
                }
            }
            die();
            // die(implode('', $buffer));
        } );
    } );

    $earlyConsole->writeLn( 'error handler' );

    // lazy ass exception handling
    set_exception_handler( function ( $e ) {

        (new Console() )->cls();

        echo $e->getTraceAsString() . PHP_EOL . PHP_EOL . PHP_EOL;
        print_r( $e );
        die();
    } );

    set_error_handler( function ( $errno, $errstr, $errfile, $errline ) {
        throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
    } );

    $earlyConsole->writeLn( 'registering resize handler' );

    $cli->updateDimensions();
    $cli->cls();

    $sourceFrame = new ConsoleFrame( $cli );
    $sourceFrame->setPosition( 0, 2 );
    $sourceFrame->setDimension( $cli->getWidth() - 100, $cli->getHeight() - 2 );

    $stackFrame = new ConsoleFrame( $cli );
    $stackFrame->setPosition( $cli->getWidth() - 100, 2 );
    $stackFrame->setDimension( $cli->getWidth() - 100, $cli->getHeight() - 2 );

    pcntl_async_signals( true );

    pcntl_signal(
        SIGWINCH,
        function () use ( $cli, &$forceRedraw, $stackFrame, $sourceFrame ): void {
        $cli->updateDimensions();
        $forceRedraw = true;

        $sourceFrame->setDimension( $cli->getWidth() - 100, $cli->getHeight() - 2 );

        $stackFrame->setPosition( $cli->getWidth() - 100, 2 );
        $stackFrame->setDimension( $cli->getWidth() - 100, $cli->getHeight() - 2 );
    }
    );


    $displayUpdate        = false;
    $forceRedraw          = true;
    $functionList         = $debugger->fetchFunctions();
    $functionListFiltered = $functionList;


    $filterCallback = (function ( $buffer ) use ( &$functionListFiltered, &$functionList, $debugger ){

        $selectedFunction = array_shift($functionListFiltered);

        // reset filter
        $functionListFiltered = $functionList;

//        $debugger->setStartingBreakpoint( $selectedFunction['oid'] );
        $debugger->setStartingBreakpoint(43290);
        $debugger->init();
        $debugger->waitForConnection();
    });

    // initialize debugger after main breakpoint
    $triggerReadMode = (function () use ( $input, $filterCallback ) {
            $input->readMode( $filterCallback );
        } );

    // moved down for handling vars, this needs to be its own class
        // terminate and go back to input list
    $input->registerKey( 'F10',
        [$debugger, 'abort'],
        [$debugger, 'reset'],
        [$cli, 'cls'],
        $triggerReadMode
    );

    $triggerReadMode();



    // cursor
    $input->registerKey( 'down', function () use ( $sourceFrame ) {
        $sourceFrame->setScrollPos( $sourceFrame->getScrollPos() + 1);
    } );
    $input->registerKey( 'up', function () use ( $sourceFrame ) {
        $sourceFrame->setScrollPos( $sourceFrame->getScrollPos() - 1);
    } );


    while ( true ) {

        $start         = microtime( 1 );
        $displayUpdate = $input->consume( $stdin );

        if ( $debugger->isInitialized() ) {

            // if not attached check for connection
            if ( !$debugger->isAttached() && $debugger->checkForConnection() ) {
                $displayUpdate = true;
            }

            if ( !$debugger->isAttached() ) {
                $cli->jump( 0, 0 );
                $cli->write( 'waiting' );
            }
        } else {


            // after keypress in readmode
            if ( $displayUpdate || $forceRedraw ) {

                $textInput = implode( "", $input->readBuffer );


                $cli->jump( 0, 0 );
                $cli->write( str_pad('Function: ' . $textInput, 50), Console::STYLE_BOLD );

                $functionListFiltered = array_filter( $functionList, function ( $func ) use ( $textInput ) {
                    return preg_match( "~$textInput~", $func['name'] ) ;
                } );

                $sourceFrame->clearBuffer();

                // draw function picker
                foreach ( $functionListFiltered as $f ) {
                    $sourceFrame->addToBuffer( "{$f['oid']} » {$f['schema']}.{$f['name']}({$f['args']})" );
                }

                $sourceFrame->render();
            }


        }


        if ( $displayUpdate || $forceRedraw ) {

            $forceRedraw = false;

            $result = $debugger->stack[$debugger->currentFrame] ?? [];
            $source = $debugger->source;
            $vars   = $debugger->vars;


            if ( $source ) {

                // sourcecode
                $sourceFrame->clearBuffer();
                $sourceFrame->addToBuffer(
                    str_repeat( ' ', 7 ) . $debugger->stack[$debugger->currentFrame]['targetname'],
                    Console::STYLE_BOLD
                );
                $sourceFrame->addToBuffer( '' );

                for ( $line = 0; $line < count( $source ); $line++ ) {

                    $sourceFrame->addToBuffer(
                        str_pad( $line + 1, 4, ' ', \STR_PAD_LEFT ) . ": " . $source[$line],
                        ( $line + 1 ) == $result['linenumber'] ? Console::STYLE_BLUE : Console::STYLE_NONE
                    );
                }

                $sourceFrame->render();

                // stack
                $stackFrame->clearBuffer();

                $stackFrame->addToBuffer( 'Frames', Console::STYLE_BOLD );
                $stackFrame->addToBuffer( '' );

                foreach ( $debugger->stack as $l ) {

                    $text = "{$l['level']} » {$l['targetname']}:{$l['func']} » {$l['args']}";
                    $stackFrame->addToBuffer(
                        $text,
                        ($l['level'] == $debugger->currentFrame ) ? Console::STYLE_BLUE : Console::STYLE_NONE
                    );
                }

                $stackFrame->addToBuffer( '' );
                $stackFrame->addToBuffer('Variables', Console::STYLE_BOLD);
                $stackFrame->addToBuffer( '' );

                // vars
                $stackFrame->addToBuffer(
                    str_pad( 'name', 20 ) .
                    str_pad( 'value', 30 ) .
                    str_pad( 'dtype', 30 ) .
                    str_pad( 'class', 6 ) .
                    str_pad( 'line', 6 ) .
                    str_pad( 'U', 3 ) .
                    str_pad( 'C', 3 ) .
                    str_pad( 'N', 3 ),
                    Console::STYLE_BOLD
                );

                foreach ( $vars as $var ) {

                    $stackFrame->addToBuffer(
                        str_pad( $var['name'], 20 ) .
                        str_pad( $var['value'], 30 ) .
                        str_pad( $var['dtype'], 30 ) .
                        str_pad( $var['varclass'], 6 ) .
                        str_pad( $var['linenumber'], 6 ) .
                        str_pad( $var['isunique'], 3 ) .
                        str_pad( $var['isconst'], 3 ) .
                        str_pad( $var['isnotnull'], 3 )
                    );
                }

                // breakpoints

                $stackFrame->addToBuffer('');
                $stackFrame->addToBuffer('Breakpoints', Console::STYLE_BOLD);
                $stackFrame->addToBuffer( '' );

                if ( !is_array($debugger->breakpoints)) {
                    var_dump($debugger->breakpoints);
                    die();
                }


                foreach( $debugger->breakpoints as $bp ) {
                    $stackFrame->addToBuffer("{$bp['func']} » {$bp['linenumber']} » {$bp['targetname']}");
                }

                $stackFrame->render();
            }
        }

        $frametime = ( microtime( 1 ) - $start ) * 1000;

        if ( ( 1 / $targetFps * 1000 ) > $frametime ) {
            usleep(
                (
                1 / $targetFps * 1000 // frames per ms
                - $frametime
                ) * 1000  // µs
            );
        }

        $frametime = ( microtime( 1 ) - $start ) * 1000;


        $cli->jump( 0, $cli->getHeight() );
        $cli->write( implode( '', $input->readBuffer ) );

        $cli->jump( $cli->getWidth() - strlen( '[pgdown] fps: 30.00' ), $cli->getHeight() );
        $cli->write( str_pad( "[{$input->lastKey}]", 8 ) . '  fps: ' . number_format( (1 / $frametime) * 1000, 2 ) );
    }
