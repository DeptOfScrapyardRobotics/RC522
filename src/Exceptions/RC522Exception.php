<?php

namespace ScrapyardIO\Sensors\RFID\RC522\Exceptions;

use ScrapyardIO\Sensors\RFID\Exceptions\RFIDSensorException;

class RC522Exception extends RFIDSensorException
{
    public static function deviceNotReady(string $addl_msg = ""): static
    {
        return new static("Device not ready $addl_msg.");
    }
}
