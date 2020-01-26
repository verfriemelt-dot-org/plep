<?php

    class Debugger {

        private $pg;
        public $stack       = [];
        public $vars        = [];
        public $source      = [];
        public $breakpoints = [];
        private $channel = null;
        private $async_result;
        private $initialized = false;
        private $attached    = false;
        public $currentFrame = null;

        public function setConnectionHandle( $pg ) {
            $this->pg = $pg;
            return $this;
        }

        public function isInitialized(): bool {
            return $this->initialized;
        }

        public function isAttached(): bool {
            return $this->attached;
        }

        public function init() {

            // setup channel

            $this->channel = pg_fetch_assoc( pg_query( 'select pldbg_create_listener()' ) )['pldbg_create_listener'];

            // oid hardcoded to given functions
            $this->addGlobalBreakPoint( 44566 );
        }

        public function incrementStackFrame() {
            if ( $this->currentFrame === null || $this->currentFrame + 1 >= count( $this->stack ) ) {
                // nope
                return;
            }

            $this->currentFrame++;
            pg_query( "select pldbg_select_frame({$this->channel},{$this->currentFrame})" );
        }

        public function decrementStackFrame() {

            if ( $this->currentFrame === null || $this->currentFrame - 1 < 0 ) {
                // nope
                return;
            }

            $this->currentFrame--;
            pg_query( "select pldbg_select_frame({$this->channel},{$this->currentFrame})" );
        }

        public function stepInto() {
            pg_query( "select pldbg_step_into({$this->channel})" );
        }

        public function stepOver() {
            pg_query( "select pldbg_step_over({$this->channel})" );
        }

        public function continue() {
            pg_query( "select pldbg_continue({$this->channel})" );
        }

        public function abort() {
            pg_query( "select pldbg_abort_target({$this->channel})" );
        }

        public function updateSource() {
            $this->source = explode( "\n", pg_fetch_assoc( pg_query( "select pldbg_get_source as src  from pldbg_get_source({$this->channel}," . $this->stack[$this->currentFrame]['func'] . ")" ) )['src'] );
        }

        public function updateStack() {
            $this->stack        = pg_fetch_all( pg_query( "select * from pldbg_get_stack({$this->channel})" ) ) ?? [];
            $this->currentFrame = 0;
        }

        public function updateVars() {
            $this->vars = pg_fetch_all( pg_query( "select *, pg_catalog.format_type(dtype, NULL) as dtype from pldbg_get_variables({$this->channel})" ) ) ?? [];
        }

        public function updateBreakpoints() {
            $this->breakpoints = pg_fetch_all( pg_query( "select * from pldbg_get_breakpoints({$this->channel})" ) );
        }

        public function addGlobalBreakPoint( int $oid, int $line = null ) {
            pg_query( "select pldbg_set_global_breakpoint({$this->channel},{$oid},null,null)" );
        }

        public function waitForConnection() {
            $this->async_result = pg_send_query( $this->pg, "select pldbg_wait_for_target({$this->channel})" );
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

        public function refresh() {
            $this->updateStack();
            $this->updateSource();
            $this->updateVars();
            $this->updateBreakpoints();
        }

    }
    