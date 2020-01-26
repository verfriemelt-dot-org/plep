<?php

    class ConsoleFrame {

        private $cli;
        private $pos, $height, $width, $border = false, $buffer = [];

        public function __construct( Console $cli ) {
            $this->cli = $cli;

            $this->height = $cli->getHeight();
            $this->width  = $cli->getWidth();
        }

        public function setPosition( $x, $y ) {
            $this->pos = [
                'x' => $x,
                'y' => $y,
            ];

            return $this;
        }

        public function setDimension( $width, $height ) {

            $this->width  = $width;
            $this->height = $height;

            return $this;
        }

        // if window overflows the window with limit blanking width
        // to stay within borders
        private function getRenderWidth(): int {

            if ( $this->cli->getWidth() < $this->pos['x'] + $this->width ) {
                return $this->cli->getWidth() - $this->pos['x'];
            }

            return $this->width;
        }

        // wipes rectangle with spaces
        private function blank() {

            for (
            $h = 0; $h <= $this->height && $h < ($this->cli->getHeight() - $this->pos['y']); $h++
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
            foreach ( $this->buffer as [$line, $style] ) {

                $this->cli->jump( $this->pos['x'], $this->pos['y'] + $offset );
                $this->cli->write( $line, $style );
                $offset++;
            }
        }

    }
    