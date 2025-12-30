<?php

namespace ScrapyardIO\Sensors\RFID\RC522\Concerns;

use ScrapyardIO\Transports\SPITransport;
use ScrapyardIO\Transports\Concerns\BusyPin;
use ScrapyardIO\Transports\Concerns\ResetPin;

trait RC522SPIChip
{
    use ResetPin, BusyPin;

    protected ?SPITransport $rc522_spi = null;
    protected int $rc522_spi_bus = 1;
    protected int $spi_rc522_chip_select = 0;
    protected int $max_packet_size = 64;

    protected function spi_rc522_bus(?int $bus = null): int
    {
        if(!is_null($bus))
        {
            $this->rc522_spi_bus = $bus;
        }
        return $this->rc522_spi_bus;
    }

    protected function spi_rc522_chip_select(?int $cs = null): int
    {
        if($cs)
        {
            $this->spi_rc522_chip_select = $cs;
        }
        return $this->spi_rc522_chip_select;
    }

    protected function rc522_spi(): ?SPITransport
    {
        if(empty($this->rc522_spi))
        {
            $this->rc522_spi = new SPITransport(
                $this->spi_rc522_bus(),
                $this->spi_rc522_chip_select(),
                0,
                1000000,
                0
            );
        }

        return $this->rc522_spi;
    }

    public function readData(int $command, int $num_bytes_to_read, bool $set_read_bit = false): array
    {
        return $this->rc522_spi()->read($command, $num_bytes_to_read, $set_read_bit);
    }

    public function sendCommand(array $bytes): void
    {
        $this->rc522_spi()->notify($bytes);
    }
}
