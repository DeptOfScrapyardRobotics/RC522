<?php

namespace ScrapyardIO\Sensors\RFID\RC522\Concerns;

use ScrapyardIO\Sensors\RFID\RC522\Enums\RC522Action;
use ScrapyardIO\Sensors\RFID\RC522\Enums\RC522Command;
use ScrapyardIO\Sensors\RFID\RC522\Exceptions\RC522Exception;
use ScrapyardIO\Support\DataManipulation\ByteRegister;

trait RC522BootSequence
{
    protected bool $rc522_debug = false;

    /**
     * Enable/disable RC522 debug output (stderr).
     */
    public function debug(bool $enabled = true): static
    {
        $this->rc522_debug = $enabled;
        return $this;
    }

    protected function dbg(string $message, array $context = []): void
    {
        if (!$this->rc522_debug) {
            return;
        }

        $prefix = '[RC522] ';
        $ctx = '';

        if (!empty($context)) {
            $ctx = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $line = $prefix . $message . $ctx . PHP_EOL;

        if (defined('STDERR') && is_resource(STDERR)) {
            fwrite(STDERR, $line);
        } else {
            error_log(rtrim($line));
        }
    }

    protected function hexByte(int $v): string
    {
        return sprintf('0x%02X', $v & 0xFF);
    }

    protected function hexBytes(array $bytes): array
    {
        return array_map(fn ($b) => $this->hexByte((int) $b), $bytes);
    }

    public function rstPin(int $chip, int $line): static
    {
        $this->rst_chip($chip);
        $this->rst_line($line);
        $this->rst_gpio();

        return $this;
    }

    public function busyPin(int $chip, int $line): static
    {
        $this->busy_chip($chip);
        $this->busy_line($line);
        $this->busy_gpio();

        return $this;
    }

    public function reset(): void
    {
        $this->rstLow();
        usleep(2);
        $this->rstHigh();
        $this->wait(50);
    }

    protected function resetBaudRates(): void
    {
        $this->sendCommand([RC522Command::TX_MODE->value, 0x00]);
        $this->sendCommand([RC522Command::RX_MODE->value, 0x00]);
    }

    protected function resetModulationWidth(): void
    {
        // @todo - get the other options for this setting
        $this->sendCommand([RC522Command::MOD_WIDTH->value, 0x26]);
    }

    protected function configTimer(): void
    {
        // @todo - get the other options for this setting
        $this->sendCommand([RC522Command::TIMER_MODE->value, 0x80]);

        // @todo - get the other options for this setting
        $this->sendCommand([RC522Command::TIMER_PRESCALER->value, 0xA9]);

        // @todo - get the other options for this 16-bit setting
        $this->sendCommand([RC522Command::TIMER_RELOAD_HIGH->value, 0x03]);
        $this->sendCommand([RC522Command::TIMER_RELOAD_LOW->value, 0xE8]);
    }

    protected function configASKModulation(): void
    {
        // @todo - get the other options for this setting
        $this->sendCommand([RC522Command::TX_ASK->value, 0x40]);
    }

    protected function configCRCCoProcessor(): void
    {
        // @todo - get the other options for this setting
        $this->sendCommand([RC522Command::MODE->value, 0x3D]);
    }

    protected function enableAntenna(): void
    {
        [$echo, $value] = $this->readData(RC522Command::TX_CONTROL->value, 1, true);
        $breakdown = (new ByteRegister($value))->toBools();
        if((!$breakdown[0]) || (!$breakdown[1]))
        {
            // This turns on the TX1 and TX2 pins in the device (the antenna)
            $new_value = $value | 0x03;
            $this->sendCommand([RC522Command::TX_CONTROL->value, $new_value]);
        }
    }

    protected function executeCommand(RC522Action $action): void
    {
        $this->sendCommand([
            RC522Command::COMMAND->value,
            $action->value
        ]);
    }

    protected function flushFIFO(): void
    {
        $this->sendCommand([RC522Command::FIFO_LEVEL->value, 0x80]);
    }

    protected function startRequestA(): void
    {
        $this->sendCommand([
            RC522Command::FIFO_DATA->value,
            RC522Action::REQUEST_TYPE_A->value
        ]);
    }

    protected function clearInterrupts(): void
    {
        $this->sendCommand([RC522Command::COMM_INTERRUPT_REQUEST->value, 0x7F]);
    }

    protected function setBitFraming(int $value): void
    {
        $this->sendCommand([RC522Command::BIT_FRAMING->value, $value]);
    }

    protected function clearBitMask(RC522Command $reg, int $mask): void
    {
        [$echo, $current] = $this->readData($reg->value, 1, true);
        $this->sendCommand([$reg->value, $current & (~$mask)]);
    }

    protected function setBitMask(RC522Command $reg, int $mask): void
    {
        [$echo, $current] = $this->readData($reg->value, 1, true);
        $this->sendCommand([$reg->value, $current | $mask]);
    }

    protected function cleanup(): void
    {
        $this->executeCommand(RC522Action::IDLE);
        $this->clearInterrupts();
        $this->flushFIFO();
    }


    public function prepTagDetection(): bool
    {
        $this->cleanup();
        $this->startRequestA();
        $this->setBitFraming(0x07);
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitFraming(0x80 | 0x07);
        if(!$this->readyWait(500, 0x30)) return false;
        if($error_status = $this->checkForErrors()) return false;
        return true;

    }

    /**
     * Wait for a command completion IRQ in CommIrqReg.
     *
     * Common masks:
     * - Transceive: 0x30 (RxIRq | IdleIRq)
     * - Auth:      0x10 (IdleIRq)
     */
    protected function readyWait(int $timeout_ms = 1000, int $wait_irq_mask = 0x30): bool
    {
        $ready = false;
        $start = microtime(true);
        while(!$ready)
        {
            [$echo, $ready_byte] = $this->readData(RC522Command::COMM_INTERRUPT_REQUEST->value, 1, true);
            $is_ready_byte = $ready_byte & $wait_irq_mask;
            $ready = $is_ready_byte > 0;
            if(!$ready) {
                if ((microtime(true) - $start) * 1000 > $timeout_ms) {
                    $this->dbg('readyWait: timeout', [
                        'timeout_ms' => $timeout_ms,
                        'CommIrqReg' => $this->hexByte($ready_byte),
                        'wait_irq_mask' => $this->hexByte($wait_irq_mask),
                    ]);
                    return false;
                }
                $this->wait(50);
            }
        }
        return true;
    }

    protected function checkForErrors(): int|bool
    {
        [$echo, $error_status] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);

        if($error_status & 0x1B) {
            // Error bits set (BufferOvfl, ParityErr, ProtocolErr)
            return $error_status & 0x10;
        }

        return false;
    }
    /**
     *
     * @return array|null
     */
    public function rawUUID(): ?array
    {
        $results = null;

        if($this->prepTagDetection())
        {
            [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
            if($fifo_length == 2)
            {
                $atqa = [];
                for($i = 0; $i < $fifo_length; $i++) {
                    [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                    $atqa[] = $byte;
                }

                $results = [
                    'num_targets' => 1,
                    'target_number' => 1,
                    'atqa_low' => $atqa[1],
                    'atqa_high' => $atqa[0],
                ];

                $this->cleanup();

                $this->sendCommand([RC522Command::FIFO_DATA->value, RC522Action::SELECT_CASCADE_LEVEL_1->value]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, 0x20]);
                $this->setBitFraming(0x00);
                $this->executeCommand(RC522Action::TRANSCEIVE);
                $this->setBitFraming(0x80);

                $this->readyWait();
                // Don't call IDLE - keep card in ACTIVE state for SELECT
                // $this->executeCommand(RC522Action::IDLE);
                if($error_status = $this->checkForErrors()) return null;


                // Read FIFO (should be 5 bytes)
                [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
                if($fifo_length != 5) return null;

                $uid = [];
                for($i = 0; $i < 5; $i++) {
                    [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                    $uid[] = $byte;
                }

                $bcc = $uid[0] ^ $uid[1] ^ $uid[2] ^ $uid[3];
                if($bcc != $uid[4]) return null; // Bad checksum

                if($uid[0] == 0x88)
                {
                    $results['uid_len'] = 7;
                    $results['uid0'] = $uid[1];
                    $results['uid1'] = $uid[2];
                    $results['uid2'] = $uid[3];

                    // SELECT CASCADE LEVEL 1 first
                    $this->cleanup();
                    $this->sendCommand([RC522Command::FIFO_DATA->value, 0x93]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, 0x70]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[0]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[1]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[2]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[3]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[4]]);

                    $this->executeCommand(RC522Action::CALCULATE_CRC);
                    $crc_ready = false;
                    while(!$crc_ready) {
                        [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
                        if($irq & 0x04) $crc_ready = true;
                    }
                    [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
                    [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

                    $this->executeCommand(RC522Action::IDLE);
                    $this->flushFIFO();
                    $this->sendCommand([RC522Command::FIFO_DATA->value, 0x93]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, 0x70]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[0]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[1]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[2]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[3]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[4]]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);

                    $this->setBitFraming(0x00);
                    $this->executeCommand(RC522Action::TRANSCEIVE);
                    $this->setBitFraming(0x80);
                    $this->readyWait();
                    // Don't call IDLE - keep card in ACTIVE state for cascade level 2
                    // $this->executeCommand(RC522Action::IDLE);

                    // Now CASCADE LEVEL 2 anti-collision
                    $this->cleanup();
                    $this->sendCommand([RC522Command::FIFO_DATA->value, 0x95]);
                    $this->sendCommand([RC522Command::FIFO_DATA->value, 0x20]);
                    $this->setBitFraming(0x00);
                    $this->executeCommand(RC522Action::TRANSCEIVE);
                    $this->setBitFraming(0x80);

                    $this->readyWait();
                    // Don't call IDLE - keep card in ACTIVE state for SELECT
                    // $this->executeCommand(RC522Action::IDLE);
                    if($error_status = $this->checkForErrors()) return null;

                    [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
                    if($fifo_length != 5) return null;

                    $uid_level2 = [];
                    for($i = 0; $i < 5; $i++) {
                        [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                        $uid_level2[] = $byte;
                    }

                    $bcc = $uid_level2[0] ^ $uid_level2[1] ^ $uid_level2[2] ^ $uid_level2[3];
                    if($bcc != $uid_level2[4]) return null;

                    $results['uid3'] = $uid_level2[0];
                    $results['uid4'] = $uid_level2[1];
                    $results['uid5'] = $uid_level2[2];
                    $results['uid6'] = $uid_level2[3];

                    $uid = $uid_level2; // Use level 2 UID for SELECT

                }
                else
                {
                    $results['uid_len'] = 4;
                    $results['uid0'] = $uid[0];
                    $results['uid1'] = $uid[1];
                    $results['uid2'] = $uid[2];
                    $results['uid3'] = $uid[3];
                }


                $this->cleanup();

                $cascade_level = ($results['uid_len'] == 7) ? 0x95 : 0x93;
                $this->sendCommand([RC522Command::FIFO_DATA->value, $cascade_level]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, 0x70]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[0]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[1]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[2]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[3]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[4]]);

                $this->executeCommand(RC522Action::CALCULATE_CRC);

                $crc_ready = false;
                while(!$crc_ready) {
                    [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
                    if($irq & 0x04) $crc_ready = true;
                }

                [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
                [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

                $this->executeCommand(RC522Action::IDLE);
                $this->flushFIFO();

                // Write ALL 9 bytes fresh
                $this->sendCommand([RC522Command::FIFO_DATA->value, $cascade_level]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, 0x70]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[0]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[1]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[2]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[3]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $uid[4]]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
                $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);

                $this->setBitFraming(0x00);
                $this->executeCommand(RC522Action::TRANSCEIVE);
                $this->setBitFraming(0x80);

                $this->readyWait();
                // Don't call IDLE - keep card selected for subsequent operations
                // $this->executeCommand(RC522Action::IDLE);

                [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);

                [$echo, $sak] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                $response = $results;

                $results = [
                    'num_targets' => $response['num_targets'],
                    'target_number' => $response['target_number'],
                    'atqa_low' => $response['atqa_low'],
                    'atqa_high' => $response['atqa_high'],
                    'sak' => $sak,
                    'uid_len' => $response['uid_len'],
                    'uid' => []
                ];

                switch($results['uid_len'])
                {
                    case 10:
                        $results['uid'][9] = $response['uid9'];
                        $results['uid'][8] = $response['uid8'];
                        $results['uid'][7] = $response['uid7'];

                    case 7:
                        $results['uid'][6] = $response['uid6'];

                    case 6:
                        $results['uid'][5] = $response['uid5'];

                    case 5:
                        $results['uid'][4] = $response['uid4'];

                    case 4:
                        $results['uid'][3] = $response['uid3'];
                        $results['uid'][2] = $response['uid2'];
                        $results['uid'][1] = $response['uid1'];
                        $results['uid'][0] = $response['uid0'];
                }
            }
        }

        return $results;
    }

    /**
     * Check if card is password protected
     * @param array $card_data Result from rawUUID()
     * @return bool|null True if protected, false if not, null if unable to determine
     */
    public function checkPasswordProtected(array $card_data): ?bool
    {
        $sak = $card_data['sak'];

        // Mifare Ultralight/NTAG (SAK = 0x00)
        if($sak === 0x00) {
            return $this->checkUltralightPasswordProtectedRC522();
        }

        // Mifare Classic variants (SAK = 0x08, 0x09, 0x18)
        if(in_array($sak, [0x08, 0x09, 0x18])) {
            return $this->checkClassicPasswordProtectedRC522($card_data['uid']);
        }

        // DESFire (SAK = 0x20/32)
        if($sak === 0x20) {
            return $this->checkDesfirePasswordProtectedRC522();
        }

        // Unknown card type
        return null;
    }

    /**
     * Check if Mifare Ultralight/NTAG card is password protected via RC522
     * Reads page 0x84 to check MIRROR_PAGE byte
     */
    protected function checkUltralightPasswordProtectedRC522(): ?bool
    {
        // Mifare Ultralight READ command: 0x30 + page
        // Card may have timed out due to PHP execution gap - retry if needed

        // Try up to 3 times to read the page
        for($attempt = 1; $attempt <= 3; $attempt++) {

            if($attempt > 1) {
                // Wait longer between retries
                $this->wait(50);
            }

            $this->clearInterrupts();
            $this->flushFIFO();

            // Write READ command for CRC calculation
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x30]);  // READ command
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x83]);  // Page 0x83 (AUTH0 config)

        // Calculate CRC
        $this->executeCommand(RC522Action::CALCULATE_CRC);
        $crc_ready = false;
        while(!$crc_ready) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) $crc_ready = true;
        }

            [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
            [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

            // CALCULATE_CRC consumed FIFO, so rewrite everything
            $this->executeCommand(RC522Action::IDLE);
            $this->flushFIFO();
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x30]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x83]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);

            $this->setBitFraming(0x00);
            $this->executeCommand(RC522Action::TRANSCEIVE);
            $this->setBitFraming(0x80);  // StartSend bit

            $this->readyWait();
            // Don't call IDLE - keep card in ACTIVE state for subsequent operations
            // $this->executeCommand(RC522Action::IDLE);

            [$echo, $error_status] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
            if($error_status & 0x1B) {
                if($attempt < 3) {
                    continue;  // Try again
                }
                return null;  // Error occurred on all attempts
            }

            [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
            if($fifo_length < 4) {
                if($attempt < 3) {
                    continue;  // Try again
                }
                return null;  // All attempts failed
            }

            // Success! Read all 4 bytes (16 bytes total for 4 pages)
            // Page 0x83: [MIRROR, RFUI, MIRROR_CONF, AUTH0]
            // We need byte 3 (AUTH0)
            $data = [];
            for($i = 0; $i < 4; $i++) {
                [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                $data[] = $byte;
            }
            
            // AUTH0 is byte 3
            // 0xFF = no authentication required (not password protected)
            // Any other value = authentication required from that page onwards
            $auth0 = $data[3];
            
            return $auth0 !== 0xFF;
        }

        // Should never reach here
        return null;
    }

    /**
     * Check if Mifare Classic card is password protected via RC522
     *
     * TODO: Implement proper MIFARE authentication
     * Requires loading keys into key buffer register and proper auth sequence
     * For now, assume Classic cards using default keys are not protected
     */
    protected function checkClassicPasswordProtectedRC522(array $uid): ?bool
    {
        // MIFARE Classic authentication on RC522 is complex and requires:
        // 1. Loading 6-byte key into key buffer (separate register, not FIFO)
        // 2. Writing auth command + block + card serial to FIFO
        // 3. Executing MIFARE_AUTHENTICATE command
        // 4. Checking MFCrypto1On bit in STATUS register
        //
        // For now, return false (assume not protected with default keys)
        // Most hobby cards use default keys
        return false;
    }

    /**
     * Check if Mifare DESFire card is password protected via RC522
     * Sends GET_KEY_SETTINGS command to check master application access
     */
    protected function checkDesfirePasswordProtectedRC522(): ?bool
    {
        // Card is already selected from rawUUID()
        $this->clearInterrupts();
        $this->flushFIFO();

        // DESFire GET_KEY_SETTINGS command: 0x45
        // Wrapped in ISO 14443-4 frame (no wrapping needed for direct command)
        $this->sendCommand([RC522Command::FIFO_DATA->value, 0x45]);

        // Calculate CRC
        $this->executeCommand(RC522Action::CALCULATE_CRC);
        $crc_ready = false;
        while(!$crc_ready) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) $crc_ready = true;
        }

        [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
        [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

        // Rewrite command with CRC
        $this->executeCommand(RC522Action::IDLE);
        $this->flushFIFO();
        $this->sendCommand([RC522Command::FIFO_DATA->value, 0x45]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);

        $this->setBitFraming(0x00);
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitFraming(0x80);

        $this->readyWait();
        $this->executeCommand(RC522Action::IDLE);

        [$echo, $error_status] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        if($error_status & 0x1B) {
            // Command failed - likely protected or requires authentication
            return true;
        }

        [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
        if($fifo_length < 1) {
            // No response - treat as protected
            return true;
        }

        // Read status byte
        [$echo, $status] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);

        // DESFire status codes:
        // 0x00 = Success (command executed, not protected)
        // 0x9D = Permission denied
        // ISO 7816 error codes: 0x6F, 0x67, etc. (protected)
        // Anything other than 0x00 means protected or authentication required
        return $status !== 0x00;
    }

    /**
     * Check if card is writable
     * @param array $card_data Result from rawUUID()
     * @return bool|null True if writable, false if locked, null if unable to determine
     */
    public function isWritable(array $card_data): ?bool
    {
        $sak = $card_data['sak'];

        // Mifare Ultralight/NTAG (SAK = 0x00)
        if($sak === 0x00) {
            return $this->checkUltralightWritableRC522();
        }

        // Mifare Classic variants (SAK = 0x08, 0x09, 0x18)
        if(in_array($sak, [0x08, 0x09, 0x18])) {
            return $this->checkClassicWritableRC522($card_data['uid']);
        }

        // DESFire (SAK = 0x20/32) - production cards are write-protected
        if($sak === 0x20) {
            return false;
        }

        // Unknown card type
        return null;
    }

    /**
     * Check if Mifare Ultralight/NTAG card is writable via RC522
     * Reads lock bytes at pages 2-3
     */
    protected function checkUltralightWritableRC522(): ?bool
    {
        // Card is still selected from rawUUID() - we never called IDLE
        // BUT there's a time gap from PHP execution, so card needs time to be ready
        $this->wait(50);  // Give card time after PHP execution gap
        $this->clearInterrupts();
        $this->flushFIFO();

        // Read page 2 (contains static lock bytes)
        $this->sendCommand([RC522Command::FIFO_DATA->value, 0x30]);  // READ command
        $this->sendCommand([RC522Command::FIFO_DATA->value, 0x02]);  // Page 2

        // Calculate CRC
        $this->executeCommand(RC522Action::CALCULATE_CRC);
        $crc_ready = false;
        while(!$crc_ready) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) $crc_ready = true;
        }

        [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
        [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

        // Rewrite command with CRC
        $this->executeCommand(RC522Action::IDLE);
        $this->flushFIFO();
        $this->sendCommand([RC522Command::FIFO_DATA->value, 0x30]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, 0x02]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);

        $this->setBitFraming(0x00);
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitFraming(0x80);

        $this->readyWait();
        // Don't call IDLE - keep card in ACTIVE state for subsequent operations
        // $this->executeCommand(RC522Action::IDLE);

        [$echo, $error_status] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        if($error_status & 0x1B) {
            return null;
        }

        [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
        if($fifo_length < 16) {
            return null;
        }

        // Read 16 bytes (pages 2-5)
        $data = [];
        for($i = 0; $i < 16; $i++) {
            [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
            $data[] = $byte;
        }

        // NTAG/Ultralight lock bytes structure (reading pages 2-5):
        // Page 2: [internal, lock0, lock1, <page3>]
        //   lock0 (byte 2): Static lock bits for pages 3-10
        //   lock1 (byte 3): Static lock bits for pages 11-15
        // Page 3: [otp0, otp1, otp2, otp3]
        //   OTP bytes (can be write-once)
        
        // Check static lock bytes
        $static_lock_0 = $data[2];  // Page 2, byte 2
        $static_lock_1 = $data[3];  // Page 2, byte 3
        
        // For NTAG213/215/216, page 3 contains Capability Container (CC):
        // Byte 0: Magic (0xE1)
        // Byte 1: Version (0x10)
        // Byte 2: Memory size
        // Byte 3: Read/Write access (bits 7-4: read, bits 3-0: write)
        //         0x00 = read/write allowed, 0x0F = write forbidden
        $cc_byte_3 = $data[7];  // Page 3, byte 3
        
        // Check if write access is forbidden (bits 3-0 = 0xF)
        $cc_write_forbidden = ($cc_byte_3 & 0x0F) === 0x0F;
        
        // Card is NOT writable if:
        // 1. Any static lock bits are set (pages 3-15 locked)
        // 2. CC indicates write is forbidden
        if($static_lock_0 !== 0x00 || $static_lock_1 !== 0x00 || $cc_write_forbidden) {
            return false;  // Card is write-protected
        }
        
        return true;  // Card is fully writable
    }

    /**
     * Check if Mifare Classic card is writable via RC522
     * TODO: Implement proper authentication and access bit checking
     * For now, assume Classic cards are not writable (too complex for RC522)
     */
    protected function checkClassicWritableRC522(array $uid): ?bool
    {
        // Mifare Classic write checking requires:
        // 1. Authenticate with Key A or B
        // 2. Read sector trailer
        // 3. Parse access bits
        // RC522 MIFARE authentication is complex, return null for now
        return null;
    }

    /**
     * Read pages from an NFC tag (NTAG/Ultralight)
     * @param int $start_page Starting page number
     * @param int $num_pages Number of pages to read
     * @return array|null Array of bytes from all pages, or null on failure
     */
    public function readPages(int $start_page, int $num_pages): ?array
    {
        $all_data = [];

        // Ultralight READ command returns 4 pages (16 bytes) at a time
        for($page = $start_page; $page < $start_page + $num_pages; $page += 4) {
            // Card is still selected from rawUUID() - we never called IDLE
            // BUT there's a time gap from PHP execution, so card needs time to be ready
            if($page === $start_page) {
                $this->wait(50);  // Give card time after PHP execution gap
            }
            $this->clearInterrupts();
            $this->flushFIFO();

            // Write READ command for CRC calculation
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x30]);  // READ command
            $this->sendCommand([RC522Command::FIFO_DATA->value, $page]);  // Page number

            // Calculate CRC
            $this->executeCommand(RC522Action::CALCULATE_CRC);
            $crc_ready = false;
            while(!$crc_ready) {
                [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
                if($irq & 0x04) $crc_ready = true;
            }

            [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
            [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

            // Rewrite command with CRC
            $this->executeCommand(RC522Action::IDLE);
            $this->flushFIFO();
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x30]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, $page]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);

            $this->setBitFraming(0x00);
            $this->executeCommand(RC522Action::TRANSCEIVE);
            $this->setBitFraming(0x80);

            $this->readyWait();
            // Don't call IDLE - keep card in ACTIVE state for subsequent page reads
            // $this->executeCommand(RC522Action::IDLE);

            [$echo, $error_status] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
            if($error_status & 0x1B) {
                return null;
            }

            [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
            if($fifo_length < 16) {
                return null;
            }

            // Read 16 bytes (4 pages × 4 bytes)
            for($i = 0; $i < 16; $i++) {
                [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                $all_data[] = $byte;
            }
        }

        // Trim to exactly the requested number of pages
        return array_slice($all_data, 0, $num_pages * 4);
    }

    /**
     * Write a single page to Mifare Ultralight/NTAG tag
     *
     * @param int $page Page number to write to (4-39 typically writable)
     * @param array $data 4 bytes of data to write
     * @return bool Success status
     */
    public function writePage(int $page, array $data): bool
    {
        if(count($data) !== 4) {
            return false;
        }

        // Wait after PHP execution gap
        $this->wait(50);

        // Build write buffer: command + page + 4 data bytes
        $buffer = [0xA2, $page, ...$data];

        // Calculate CRC using coprocessor
        $this->clearInterrupts();
        $this->flushFIFO();

        foreach($buffer as $byte) {
            $this->sendCommand([RC522Command::FIFO_DATA->value, $byte]);
        }

        $this->executeCommand(RC522Action::CALCULATE_CRC);

        // Wait for CRC calculation
        $crc_ready = false;
        while(!$crc_ready) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) $crc_ready = true;
        }

        [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
        [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);

        // Rewrite command with CRC (same as readPages pattern)
        $this->executeCommand(RC522Action::IDLE);
        $this->flushFIFO();

        foreach([...$buffer, $crc_low, $crc_high] as $byte) {
            $this->sendCommand([RC522Command::FIFO_DATA->value, $byte]);
        }

        // Execute TRANSCEIVE (same as readPages pattern)
        $this->setBitFraming(0x00);
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitFraming(0x80);

        $this->readyWait();

        // Check for errors
        [$echo, $error_status] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        if($error_status & 0x1B) {
            return false;
        }

        // Check for ACK - Ultralight returns 4-bit ACK (0xA)
        [$echo, $fifo_length] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);

        if($fifo_length > 0) {
            [$echo, $ack] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
            return ($ack & 0x0F) === 0x0A;
        }

        return false;
    }

    /**
     * Write a block to Mifare Classic tag
     *
     * @param int $block Block number to write to
     * @param array $data 16 bytes of data to write
     * @param array $key_a 6-byte authentication key A
     * @param array $uid 4 or 7-byte UID for authentication
     * @return bool Success status
     */
    public function writeBlock(int $block, array $data, array $key_a, array $uid): bool
    {
        if(count($data) !== 16 || count($key_a) !== 6) {
            $this->dbg('writeBlock: invalid input sizes', [
                'block' => $block,
                'data_len' => count($data),
                'key_len' => count($key_a),
                'uid_len' => count($uid),
            ]);
            return false;
        }

        // For 7-byte UIDs, MIFARE Classic authentication uses the LAST 4 bytes.
        $uid4 = array_slice($uid, -4, 4);
        if (count($uid4) !== 4) {
            $this->dbg('writeBlock: could not derive uid4', [
                'block' => $block,
                'uid' => $this->hexBytes($uid),
            ]);
            return false;
        }

        // rawUUID() returns UID in LSB-first order, but MIFARE auth needs MSB-first
        $uid4 = array_reverse($uid4);

        $this->dbg('writeBlock: begin', [
            'block' => $block,
            'uid' => $this->hexBytes($uid),
            'uid4' => $this->hexBytes($uid4),
            'keyA' => $this->hexBytes($key_a),
        ]);

        // 1. THE "STATE RESET"
        // We must ensure the chip and card are in a clean state.
        $this->executeCommand(RC522Action::IDLE);
        $this->clearInterrupts();
        $this->flushFIFO();
        $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08); // Stop Crypto1
        $this->setBitFraming(0x00); // Make sure StartSend isn't stuck on

        // 2. AUTHENTICATION
        // Load FIFO with [AuthMode, BlockAddr, Key[0..5], UID[0..3]]
        $auth_payload = [0x60, $block, ...$key_a, ...$uid4];
        $this->dbg('auth: payload', ['payload' => $this->hexBytes($auth_payload)]);
        foreach($auth_payload as $b) {
            $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        }
        
        $this->executeCommand(RC522Action::MIFARE_AUTHENTICATE);
        
        // Wait for authentication (mirror common MFRC522 libs):
        // - Break on IdleIRq (0x10) or ErrIRq (0x02) or TimerIRq (0x01)
        // - Then validate ErrorReg and MFCrypto1On (Status2Reg bit 0x08)
        $authenticated = false;
        $i = 2000;
        while ($i--) {
            [$echo, $irq] = $this->readData(RC522Command::COMM_INTERRUPT_REQUEST->value, 1, true);
            if (($irq & 0x10) || ($irq & 0x02) || ($irq & 0x01)) {
                $this->dbg('auth: irq break', [
                    'CommIrqReg' => $this->hexByte($irq),
                    'i_left' => $i,
                ]);
                break;
            }
            usleep(100);
        }

        [$echo, $error] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        $this->dbg('auth: ErrorReg', ['ErrorReg' => $this->hexByte($error)]);
        if (($error & 0x1B) === 0x00) {
            [$echo, $status2] = $this->readData(RC522Command::COMMUNICATION_STATUS->value, 1, true);
            $authenticated = (bool) ($status2 & 0x08);
            $this->dbg('auth: Status2', [
                'Status2Reg' => $this->hexByte($status2),
                'MFCrypto1On' => (bool) ($status2 & 0x08),
            ]);
        } else {
            $this->dbg('auth: failed (error bits set)', ['ErrorReg' => $this->hexByte($error)]);
        }

        // If direct auth failed, the card likely timed out during the PHP gap.
        // We perform a full fresh selection cycle to wake it up.
        if(!$authenticated) {
            $this->dbg('auth: initial failed, attempting re-selection + retry');
            if (!$this->prepTagDetection()) {
                $this->dbg('auth(retry): prepTagDetection failed');
                return false;
            }
            $this->dbg('auth(retry): prepTagDetection OK, starting anticollision');
            
            // Anti-collision + Select Level 1
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x93]);
            $this->sendCommand([RC522Command::FIFO_DATA->value, 0x20]);
            $this->setBitFraming(0x00);
            $this->executeCommand(RC522Action::TRANSCEIVE);
            $this->setBitFraming(0x80);
            $this->readyWait();
            
            // Read the 5 bytes (UID0..3 + BCC)
            [$echo, $fl] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
            $this->dbg('auth(retry): anticollision FIFO', ['expected' => 5, 'got' => $fl]);
            if($fl !== 5) {
                $this->dbg('auth(retry): anticollision failed (wrong FIFO length)');
                return false;
            }
            $raw_uid = [];
            for($i=0; $i<5; $i++) {
                [$echo, $b] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
                $raw_uid[] = $b;
            }
            
            // Formal SELECT with CRC
            $this->executeCommand(RC522Action::IDLE);
            $this->flushFIFO();
            $select_cmd = [0x93, 0x70, ...$raw_uid];
            foreach($select_cmd as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
            $this->executeCommand(RC522Action::CALCULATE_CRC);
            $ct = 100;
            while($ct--) {
                [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
                if($irq & 0x04) break;
                usleep(100);
            }
            [$echo, $cl] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
            [$echo, $ch] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);
            
            $this->executeCommand(RC522Action::IDLE);
            $this->flushFIFO();
            foreach([...$select_cmd, $cl, $ch] as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
            $this->setBitFraming(0x00);
            $this->executeCommand(RC522Action::TRANSCEIVE);
            $this->setBitFraming(0x80);
            $this->readyWait();
            $this->dbg('auth(retry): SELECT complete, retrying auth');
            
            // Retry Auth
            $this->executeCommand(RC522Action::IDLE);
            $this->flushFIFO();
            $this->setBitFraming(0x00);
            foreach($auth_payload as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
            $this->executeCommand(RC522Action::MIFARE_AUTHENTICATE);

            $i = 2000;
            while ($i--) {
                [$echo, $irq] = $this->readData(RC522Command::COMM_INTERRUPT_REQUEST->value, 1, true);
                if (($irq & 0x10) || ($irq & 0x02) || ($irq & 0x01)) {
                    $this->dbg('auth(retry): irq break', [
                        'CommIrqReg' => $this->hexByte($irq),
                        'i_left' => $i,
                    ]);
                    break;
                }
                usleep(100);
            }

            [$echo, $error] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
            $this->dbg('auth(retry): ErrorReg', ['ErrorReg' => $this->hexByte($error)]);
            if (($error & 0x1B) === 0x00) {
                [$echo, $status2] = $this->readData(RC522Command::COMMUNICATION_STATUS->value, 1, true);
                $authenticated = (bool) ($status2 & 0x08);
                $this->dbg('auth(retry): Status2', [
                    'Status2Reg' => $this->hexByte($status2),
                    'MFCrypto1On' => (bool) ($status2 & 0x08),
                ]);
            } else {
                $this->dbg('auth(retry): failed (error bits set)', ['ErrorReg' => $this->hexByte($error)]);
            }
        }

        if(!$authenticated) {
            $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
            $this->dbg('writeBlock: fail at AUTH', ['block' => $block]);
            return false;
        }

        // 3. SEND WRITE COMMAND (0xA0 + Block)
        // Following Arduino MFRC522 library: manually calculate CRC for authenticated commands.
        // Do NOT enable TxCRCEn - we manually append CRC bytes.
        $write_cmd = [0xA0, $block];
        
        // Calculate CRC for the command
        $this->executeCommand(RC522Action::IDLE);
        $this->sendCommand([RC522Command::DIV_INTERRUPT_REQUEST->value, 0x04]); // Clear CRCIRq
        $this->flushFIFO();
        foreach($write_cmd as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        $this->executeCommand(RC522Action::CALCULATE_CRC);
        
        $crc_timeout = 100;
        while($crc_timeout--) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) break;
            usleep(100);
        }
        [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
        [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);
        
        // Now send command + CRC (following Arduino MFRC522 library exactly)
        $this->executeCommand(RC522Action::IDLE);
        $this->sendCommand([RC522Command::COMM_INTERRUPT_REQUEST->value, 0x7F]); // Clear all IRQ bits
        $this->flushFIFO();
        foreach($write_cmd as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);
        $this->setBitFraming(0x00); // TxLastBits = 0, all 8 bits valid
        
        $this->dbg('write: cmd+crc', ['cmd' => $this->hexBytes([...$write_cmd, $crc_low, $crc_high])]);
        
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitMask(RC522Command::BIT_FRAMING, 0x80); // StartSend = 1 (OR it in, don't replace!)
        $this->readyWait();
        
        // Check what happened
        [$echo, $irq] = $this->readData(RC522Command::COMM_INTERRUPT_REQUEST->value, 1, true);
        [$echo, $error] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        [$echo, $control] = $this->readData(RC522Command::CONTROL->value, 1, true);
        $this->dbg('write: cmd post-transceive', [
            'CommIrqReg' => $this->hexByte($irq),
            'ErrorReg' => $this->hexByte($error),
            'ControlReg' => $this->hexByte($control),
        ]);
        
        // Card should return 4-bit ACK (0x0A)
        [$echo, $ack_fifo] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
        $this->dbg('write: cmd fifo', ['FIFOLevel' => $ack_fifo]);
        if($ack_fifo > 0) {
            [$echo, $ack] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
            $this->dbg('write: cmd ack', ['ack' => $this->hexByte($ack), 'ack_masked' => $this->hexByte($ack & 0x0F)]);
            if(($ack & 0x0F) !== 0x0A) {
                $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
                $this->dbg('writeBlock: fail at WRITE_CMD_ACK', ['block' => $block]);
                return false;
            }
        } else {
            $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
            $this->dbg('writeBlock: fail at WRITE_CMD_NO_ACK', ['block' => $block]);
            return false;
        }

        // 4. SEND DATA BLOCK (16 bytes)
        // Calculate CRC for the data
        $this->executeCommand(RC522Action::IDLE);
        $this->sendCommand([RC522Command::DIV_INTERRUPT_REQUEST->value, 0x04]); // Clear CRCIRq
        $this->flushFIFO();
        foreach($data as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        $this->executeCommand(RC522Action::CALCULATE_CRC);
        
        $crc_timeout = 100;
        while($crc_timeout--) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) break;
            usleep(100);
        }
        [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
        [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);
        
        // Now send data + CRC (following Arduino MFRC522 library exactly)
        $this->executeCommand(RC522Action::IDLE);
        $this->sendCommand([RC522Command::COMM_INTERRUPT_REQUEST->value, 0x7F]); // Clear all IRQ bits
        $this->flushFIFO();
        foreach($data as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);
        $this->setBitFraming(0x00); // TxLastBits = 0, all 8 bits valid
        
        $this->dbg('write: data+crc', ['data+crc_len' => count($data) + 2]);
        
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitMask(RC522Command::BIT_FRAMING, 0x80); // StartSend = 1 (OR it in, don't replace!)
        $this->readyWait();
        
        // Final ACK check
        [$echo, $ack_fifo] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
        $this->dbg('write: data fifo', ['FIFOLevel' => $ack_fifo]);
        if($ack_fifo > 0) {
            [$echo, $ack] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
            $this->dbg('write: data ack', ['ack' => $this->hexByte($ack), 'ack_masked' => $this->hexByte($ack & 0x0F)]);
            $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
            return ($ack & 0x0F) === 0x0A;
        }

        $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
        $this->dbg('writeBlock: fail at WRITE_DATA_NO_ACK', ['block' => $block]);
        return false;
    }

    /**
     * Read a block from MIFARE Classic card (requires authentication)
     *
     * @param int $block Block address (0-63 for 1K, 0-255 for 4K)
     * @param array $key_a 6-byte authentication key (default: FF:FF:FF:FF:FF:FF)
     * @param array $uid Card UID for authentication
     * @return array|null 16 bytes of data, or null on failure
     */
    public function readBlock(int $block, array $key_a, array $uid): ?array
    {
        if (count($key_a) !== 6) {
            return null;
        }

        // For 7-byte UIDs, MIFARE Classic authentication uses the LAST 4 bytes.
        $uid4 = array_slice($uid, -4, 4);
        if (count($uid4) !== 4) {
            return null;
        }

        // rawUUID() returns UID in LSB-first order, but MIFARE auth needs MSB-first
        $uid4 = array_reverse($uid4);

        $this->dbg('readBlock: begin', [
            'block' => $block,
            'uid4' => $this->hexBytes($uid4),
            'keyA' => $this->hexBytes($key_a),
        ]);

        // 1. AUTHENTICATION
        $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08); // Stop any previous Crypto1
        $this->executeCommand(RC522Action::IDLE);
        $this->flushFIFO();
        $this->setBitFraming(0x00);

        $auth_payload = [0x60, $block, ...$key_a, ...$uid4];
        foreach($auth_payload as $b) {
            $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        }
        
        $this->executeCommand(RC522Action::MIFARE_AUTHENTICATE);
        
        $authenticated = false;
        $i = 2000;
        while ($i--) {
            [$echo, $irq] = $this->readData(RC522Command::COMM_INTERRUPT_REQUEST->value, 1, true);
            if (($irq & 0x10) || ($irq & 0x02) || ($irq & 0x01)) {
                break;
            }
            usleep(100);
        }

        [$echo, $error] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        if (($error & 0x1B) === 0x00) {
            [$echo, $status2] = $this->readData(RC522Command::COMMUNICATION_STATUS->value, 1, true);
            $authenticated = (bool) ($status2 & 0x08);
            $this->dbg('read: auth', ['MFCrypto1On' => $authenticated]);
        }

        if(!$authenticated) {
            $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
            $this->dbg('readBlock: AUTH failed', ['block' => $block]);
            return null;
        }

        // 2. SEND READ COMMAND (0x30 + Block) - following Arduino MIFARE_Read
        $read_cmd = [0x30, $block];
        
        // Calculate CRC for the command
        $this->executeCommand(RC522Action::IDLE);
        $this->sendCommand([RC522Command::DIV_INTERRUPT_REQUEST->value, 0x04]); // Clear CRCIRq
        $this->flushFIFO();
        foreach($read_cmd as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        $this->executeCommand(RC522Action::CALCULATE_CRC);
        
        $crc_timeout = 100;
        while($crc_timeout--) {
            [$echo, $irq] = $this->readData(RC522Command::DIV_INTERRUPT_REQUEST->value, 1, true);
            if($irq & 0x04) break;
            usleep(100);
        }
        [$echo, $crc_low] = $this->readData(RC522Command::CRC_RESULT_LOW->value, 1, true);
        [$echo, $crc_high] = $this->readData(RC522Command::CRC_RESULT_HIGH->value, 1, true);
        
        // Now send command + CRC
        $this->executeCommand(RC522Action::IDLE);
        $this->sendCommand([RC522Command::COMM_INTERRUPT_REQUEST->value, 0x7F]); // Clear all IRQ bits
        $this->flushFIFO();
        foreach($read_cmd as $b) $this->sendCommand([RC522Command::FIFO_DATA->value, $b]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_low]);
        $this->sendCommand([RC522Command::FIFO_DATA->value, $crc_high]);
        $this->setBitFraming(0x00);
        
        $this->dbg('read: cmd+crc', ['cmd' => $this->hexBytes([...$read_cmd, $crc_low, $crc_high])]);
        
        $this->executeCommand(RC522Action::TRANSCEIVE);
        $this->setBitMask(RC522Command::BIT_FRAMING, 0x80); // StartSend = 1
        $this->readyWait();
        
        // Check for errors
        [$echo, $error] = $this->readData(RC522Command::ERROR_STATUS->value, 1, true);
        if($error & 0x13) { // BufferOvfl, ParityErr, ProtocolErr
            $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
            $this->dbg('readBlock: TRANSCEIVE error', ['ErrorReg' => $this->hexByte($error)]);
            return null;
        }
        
        // Read response (should be 16 bytes + 2 CRC bytes = 18 total)
        [$echo, $fifo_len] = $this->readData(RC522Command::FIFO_LEVEL->value, 1, true);
        $this->dbg('read: response', ['FIFOLevel' => $fifo_len]);
        
        if($fifo_len < 18) {
            $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08);
            $this->dbg('readBlock: insufficient data', ['got' => $fifo_len, 'expected' => 18]);
            return null;
        }
        
        // Read 16 data bytes (ignore the 2 CRC bytes)
        $data = [];
        for($i = 0; $i < 16; $i++) {
            [$echo, $byte] = $this->readData(RC522Command::FIFO_DATA->value, 1, true);
            $data[] = $byte;
        }
        
        $this->clearBitMask(RC522Command::COMMUNICATION_STATUS, 0x08); // Stop Crypto1
        $this->dbg('readBlock: success', ['block' => $block, 'data' => $this->hexBytes($data)]);
        
        return $data;
    }

}
