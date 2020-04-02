<?php
    class KeyInput {

        protected $registeredKeys = [];

        public $lastKey = null;

        private $readMode = false;
        private $readModeCallback;

        public $readBuffer = [];

        /**
         * enables input mode, which fills up KeyInput::$readBuffer
         *
         * @param callable $callback
         * @return $this
         */
        public function readMode( callable $callback ) {
            $this->readMode = true;
            $this->readModeCallback = $callback;
            return $this;
        }

        /**
         * attached callback to key while not beeing in readMode
         *
         * @param string $key
         * @param type $callbacks
         * @return \KeyInput
         */
        public function registerKey( string $key, ... $callbacks ): KeyInput {

            foreach( $callbacks as $callback ) {
                $this->registeredKeys[$key][] = $callback;
            }

            return $this;
        }


        /**
         * tries to ingest the input
         *
         * @param type $stream
         * @return boolean
         */
        public function consume( $stream ) {

            $keybuffer = [];
            $key = null;

            for( $i = 0; $i < 6; $i++) {
              $keybuffer[$i] = ord(fgetc($stream));
            }

            if ( !$keybuffer[0] ) {
                return;
            }

            // normal keys with ?!<> etc.
            if ( $keybuffer[0] >= 33 && $keybuffer[0] <= 125 ) {

              $key = chr($keybuffer[0]);

            // alt alfa-num
            } elseif (
                 $keybuffer[0] == 27
              && $keybuffer[1] >= 48 && $keybuffer[1] <= 122
              && $keybuffer[2] == null
            ) {

              $key = 'alt-' . chr( $keybuffer[1]);
            } elseif(
                   in_array( $keybuffer[0], [ 10, 13, 127, 32 ] )
                && $keybuffer[1] == null
            ) {

              switch( $keybuffer[0] ) {
                                   
                    case 10: 
                    case 13: $key = 'enter'; break;
                    
                    case 32: $key = 'space'; break;
                    case 127: $key = 'bcksp'; break;
              }


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

            // f5-f8
            } elseif (
                 $keybuffer[0] == 27
              && $keybuffer[1] == 91
              && $keybuffer[2] == 49
              && in_array( $keybuffer[3], [53,55,56,57] )
              && $keybuffer[4] == 126
              && $keybuffer[5] == null
            ) {
                switch( $keybuffer[3] ) {
                    case 53: $key = 'F5'; break;
                    case 55: $key = 'F6'; break;
                    case 56: $key = 'F7'; break;
                    case 57: $key = 'F8'; break;
                }

            // f9-f12
            } elseif (
                 $keybuffer[0] == 27
              && $keybuffer[1] == 91
              && $keybuffer[2] == 50
              && in_array( $keybuffer[3], [48,49,51,52] )
              && $keybuffer[4] == 126
              && $keybuffer[5] == null
            ) {
                switch( $keybuffer[3] ) {
                    case 48: $key = 'F9'; break;
                    case 49: $key = 'F10'; break;
                    case 51: $key = 'F11'; break;
                    case 52: $key = 'F12'; break;
                }

            // navigation
            } elseif (
                 $keybuffer[0] == 27
              && $keybuffer[1] == 91
              && ( $keybuffer[2] >= 65 && $keybuffer[2] <= 68 || $keybuffer[2] == 72 || $keybuffer[2] == 80  )
              && $keybuffer[3] == null
            ) {
                switch( $keybuffer[2] ) {
                    case 65: $key = 'up'; break;
                    case 66: $key = 'down'; break;
                    case 67: $key = 'right'; break;
                    case 68: $key = 'left'; break;
                    case 72: $key = 'pos1'; break;
                    case 80: $key = 'del'; break;
                }
            // navigation2
            } elseif (
                 $keybuffer[0] == 27
              && $keybuffer[1] == 91
              && $keybuffer[2] >= 52 && $keybuffer[2] <= 54
              && $keybuffer[3] == 126
              && $keybuffer[4] == null
            ) {
                switch( $keybuffer[2] ) {
                    case 52: $key = 'end'; break;
                    case 53: $key = 'pgup'; break;
                    case 54: $key = 'pgdown'; break;
                }
            }

            if ( $key === null )  {
              return false;
            }

            // if in readmode do not execute callbacks
            if ( $this->readMode ) {

                if ( $key == 'bcksp' ) {
                    array_pop( $this->readBuffer );
                    return true;
                }

                if ( $key == 'enter' ) {
                    $this->readMode = false;

                    $callback = $this->readModeCallback;
                    $callback( $this->readBuffer );

                    return true;
                }

                $this->readBuffer[] = $key;
                return true;
            }


            $hadCallback = false;
            $this->lastKey = $key;

            if ( isset( $this->registeredKeys[ $key ] ) ) {
                foreach( $this->registeredKeys[ $key ] as $callback ) {
                    $callback();
                    $hadCallback = true;
                }
            }

            return $hadCallback;
        }
    }
