<?php

    class ConsoleFrame {

        private $cli;
        private $pos, $height, $width, $border = false, $buffer = [];
        private $scrollPos = 0;

        private static $instanceCount = 0;
        private $instance;


        public function __construct( Console $cli ) {

            $this->cli = $cli;

            $this->height = $cli->getHeight();
            $this->width  = $cli->getWidth();

            $this->instance = ++static::$instanceCount;
        }

        public function setPosition( $x, $y ) {
            $this->pos = [
                'x' => $x,
                'y' => $y,
            ];

            return $this;
        }

        public function setScrollPos( int $pos ): ConsoleFrame {
            $this->scrollPos = $pos;
            return $this;
        }

        public function getScrollPos( ): int {
            return $this->scrollPos;
        }

        public function setDimension( $width, $height ) {

            $this->width  = $width;
            $this->height = $height;

            return $this;
        }

        // if window overflows the window with limit blanking width
        // to stay within borders
        private function getRenderWidth(): int {

            if ( $this->cli->getWidth() -  $this->pos['x'] <= $this->width ) {
                return $this->cli->getWidth() - $this->pos['x'];
            }

            return $this->width;
        }

        private function getRenderHeight(): int {

            if ( $this->cli->getHeight() < $this->pos['y'] + $this->height ) {
                return $this->cli->getHeight() - $this->pos['y'];
            }

            return $this->height;
        }

        // wipes rectangle with spaces
        private function blank() {

            for (
            $h = 0; $h <= $this->height && $h < $this->getRenderHeight(); $h++
            ) {

                $blankWidth = $this->getRenderWidth();

                $this->cli->jump( $this->pos['x'], $this->pos['y'] + $h );
                $this->cli->write( str_repeat( " ", $blankWidth ) );
            }
        }

        public function addToBuffer( $line, $style = null ) {
            $this->buffer[] = [ $line, $style ];
            return $this;
        }

        public function clearBuffer() {
            $this->buffer = [];
            return $this;
        }

        public function render() {

            $this->blank();

            $offset = 0;

            foreach ( array_slice( $this->buffer, $this->scrollPos , $this->getRenderHeight() ) as [$line, $style] ) {


                $this->cli->jump( $this->pos['x'], $this->pos['y'] + $offset  );
//                $this->cli->write( mb_substr( $line, 0,  $this->getRenderWidth() ), $style );

                $line = str_replace( [ "\n", "\r", "\t", "\0" ], [ '', '', '', '' ], $line );

                $this->cli->write(

//                    str_pad( $this->getRenderHeight() . ":". $offset  ,6 ).

//                    str_pad(
                        substr( $line, 0,  $this->getRenderWidth() )
//                        $this->getRenderWidth(),
//                        $this->instance
//                    ),
                    , $style
                    );

                $offset++;
            }
        }

    }
