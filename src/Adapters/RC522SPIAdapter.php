<?php

namespace ScrapyardIO\Sensors\RFID\RC522\Adapters;

use ScrapyardIO\Sensors\Enums\SensorType;
use ScrapyardIO\Support\Attributes\Sensor;
use ScrapyardIO\Sensors\RFID\Adapters\RFIDSensorAdapter;
use ScrapyardIO\Sensors\RFID\RC522\Concerns\RC522SPIChip;
use ScrapyardIO\Sensors\RFID\RC522\Concerns\RC522BootSequence;


#[Sensor('RC522', 522, SensorType::RFID)]
class RC522SPIAdapter extends RFIDSensorAdapter
{
    use RC522SPIChip;
    use RC522BootSequence;

    public function bus(int $bus):static
    {
        $this->spi_rc522_bus($bus);
        return $this;
    }

    public function chipSelect(int $cs):static
    {
        $this->spi_rc522_chip_select($cs);
        return $this;
    }

    /**
     * @return $this
     *
     */
    public function boot(): static
    {
        $this->rc522_spi();
        $this->reset();
        $this->resetBaudRates();
        $this->resetModulationWidth();
        $this->configTimer();
        $this->configASKModulation();
        $this->configCRCCoProcessor();
        $this->enableAntenna();

        return $this;
    }
}
