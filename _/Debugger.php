<?php

    class Debugger {

        /**
         * postgresql handle
         * @var resource
         */
        private $pg;

        /** current stack */
        public $stack = [];

        /** variables in current stackframe */
        public $vars = [];

        /** source from current stack frame */
        public $source = [];

        /** list of all breakpoints */
        public $breakpoints = [];

        /**
         * debugging channel
         * @var int
         */
        private $channel = null;

        /**
         * debugger setup, but not waiting
         * @var bool
         */
        private $initialized = false;

        /**
         * client trapped into debugger
         * @var bool
         */
        private $attached    = false;
        public $currentFrame = null;
        private $startingBreakPoint;

        public function setConnectionHandle( $pg ): Debugger {
            $this->pg = $pg;
            return $this;
        }

        /**
         * debugger setup, but not waiting
         * @return bool
         */
        public function isInitialized(): bool {
            return $this->initialized;
        }

        /**
         * client trapped into debugger
         * @return bool
         */
        public function isAttached(): bool {
            return $this->attached;
        }

        /**
         * the debugging session needs at least one breakpoint to function
         * @param int $oid
         * @return \Debugger
         */
        public function setStartingBreakpoint( int $oid ): Debugger {

            $this->startingBreakPoint = $oid;
            return $this;
        }

        public function fetchFunctions() {

            return
                pg_fetch_all( pg_query(
                    "select
                            c.oid,
                            nspname as schema,
                            proname as name,
                            pg_get_function_identity_arguments( c.oid ) as args
                     from pg_proc c
                     join pg_namespace n on c.pronamespace = n.oid
                     join pg_language ln on c.prolang = ln.oid
                     where nspname <> 'pg_catalog' and lanname = 'plpgsql'
                " ) );
        }

        public function init(): Debugger {


            if ( $this->startingBreakPoint === null ) {
                throw new Exception('we need at least one breakpoint to start');
            }

            // setup channel
            $this->channel = pg_fetch_assoc( pg_query( 'select pldbg_create_listener()' ) )['pldbg_create_listener'];


            $this->addGlobalBreakPoint( $this->startingBreakPoint );

            $this->initialized = true;

            return $this;
        }

        /**
         *
         * @return
         */
        public function incrementStackFrame(): Debugger {

            if ( $this->currentFrame === null || $this->currentFrame + 1 >= count( $this->stack ) ) {
                return $this;
            }

            $this->currentFrame++;
            pg_query( "select pldbg_select_frame({$this->channel},{$this->currentFrame})" );

            return $this;
        }

        public function decrementStackFrame(): Debugger {

            if ( $this->currentFrame === null || $this->currentFrame - 1 < 0 ) {
                // nope
                return $this;
            }

            $this->currentFrame--;
            pg_query( "select pldbg_select_frame({$this->channel},{$this->currentFrame})" );
            return $this;
        }

        public function stepInto(): Debugger {
            pg_query( "select pldbg_step_into({$this->channel})" );
            return $this;
        }

        public function stepOver(): Debugger {
            pg_query( "select pldbg_step_over({$this->channel})" );
            return $this;
        }

        public function continue(): Debugger {
            pg_query( "select pldbg_continue({$this->channel})" );
            return $this;
        }

        public function abort(): Debugger {
            pg_query( "select pldbg_abort_target({$this->channel})" );
            return $this;
        }

        public function updateSource(): Debugger {
            $this->source = explode( "\n", pg_fetch_assoc( pg_query( "select pldbg_get_source as src  from pldbg_get_source({$this->channel}," . $this->stack[$this->currentFrame]['func'] . ")" ) )['src'] );
            return $this;
        }

        public function updateStack(): Debugger {
            $this->stack        = pg_fetch_all( pg_query( "select * from pldbg_get_stack({$this->channel})" ) ) ?? [];
            $this->currentFrame = 0;
            return $this;
        }

        public function updateVars(): Debugger {
            $this->vars = pg_fetch_all( pg_query( "select *, pg_catalog.format_type(dtype, NULL) as dtype from pldbg_get_variables({$this->channel})" ) ) ?? [];
            return $this;
        }

        public function updateBreakpoints(): Debugger {
            $this->breakpoints = pg_fetch_all( pg_query( "select * from pldbg_get_breakpoints({$this->channel})" ) );
            return $this;
        }

        public function addGlobalBreakPoint( int $oid, int $line = null ): Debugger {
            pg_query( "select pldbg_set_global_breakpoint({$this->channel},{$oid},null,null)" );
            return $this;
        }

        /**
         * starts waiting process asynchronously
         * you need to check the status with Debugger::checkForConnection()
         * @return \Debugger
         */
        public function waitForConnection(): Debugger {

            if ( !$this->initialized ) {
                throw new Exception('not initialized');
            }

            pg_send_query( $this->pg, "select pldbg_wait_for_target({$this->channel})" );
            return $this;
        }

        /**
         * checks for the debugger-target to trap into a breakpoint
         * and sets attached flag when this happens
         * @return boolean
         */
        public function checkForConnection() {

            if ( pg_connection_busy( $this->pg ) ) {
                return false;
            }

            // clear results from connection
            pg_get_result( $this->pg );

            $this->attached = true;
            return true;
        }

        /**
         * reloads all the data from the debugger-target
         * @return \Debugger
         */
        public function refresh(): Debugger {
            $this->updateStack();
            $this->updateSource();
            $this->updateVars();
            $this->updateBreakpoints();

            return $this;
        }

    }
