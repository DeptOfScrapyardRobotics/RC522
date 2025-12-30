<?php

namespace ScrapyardIO\Sensors\RFID\RC522\Enums;

enum RC522Command: int
{
    // Command and Status
    case COMMAND = 0x02;                     // Starts/stops command execution
    case COMM_INTERRUPT_ENABLE = 0x04;       // Communication interrupt enable control
    case DIV_INTERRUPT_ENABLE = 0x06;        // Divider interrupt enable control
    case COMM_INTERRUPT_REQUEST = 0x08;      // Communication interrupt request bits
    case DIV_INTERRUPT_REQUEST = 0x0A;       // Divider interrupt request bits
    case ERROR_STATUS = 0x0C;                // Register 0x06: Error status
    case STATUS_1 = 0x0E;                    // Register 0x07: Status 1
    case COMMUNICATION_STATUS = 0x10;        // Register 0x08: Status 2 (Crypto1 bit)
    case FIFO_DATA = 0x12;                   // Register 0x09: FIFO Data
    case FIFO_LEVEL = 0x14;                  // Register 0x0A: FIFO Level
    case WATER_LEVEL = 0x16;                 // FIFO underflow/overflow warning level
    case CONTROL = 0x18;                     // Miscellaneous control bits
    case BIT_FRAMING = 0x1A;                 // Bit-oriented frame adjustments
    case COLLISION_POSITION = 0x1C;          // First bit-collision position

    // Transmission and Reception
    case MODE = 0x22;                        // Transmit/receive mode settings
    case TX_MODE = 0x24;                     // Transmission data rate and framing
    case RX_MODE = 0x26;                     // Reception data rate and framing
    case TX_CONTROL = 0x28;                  // Antenna driver control (TX1/TX2)
    case TX_ASK = 0x2A;                      // Transmission modulation settings
    case TX_SELECT = 0x2C;                   // Internal antenna driver source selection
    case RX_SELECT = 0x2E;                   // Internal receiver settings
    case RX_THRESHOLD = 0x30;                // Bit decoder threshold settings
    case DEMOD = 0x32;                       // Demodulator settings
    case MIFARE_TX = 0x38;                   // MIFARE communication transmit parameters
    case MIFARE_RX = 0x3A;                   // MIFARE communication receive parameters
    case SERIAL_SPEED = 0x3E;                // UART interface speed

    // CRC and Configuration
    case CRC_RESULT_HIGH = 0x42;             // CRC calculation result MSB
    case CRC_RESULT_LOW = 0x44;              // CRC calculation result LSB
    case MOD_WIDTH = 0x48;                   // Modulation width setting
    case RF_CONFIG = 0x4C;                   // Receiver gain configuration
    case CONDUCTANCE_N = 0x4E;               // Antenna driver N conductance
    case CONDUCTANCE_P_UNMODULATED = 0x50;   // P-driver conductance (no modulation)
    case CONDUCTANCE_P_MODULATED = 0x52;     // P-driver conductance (modulation)
    case TIMER_MODE = 0x54;                  // Timer mode settings
    case TIMER_PRESCALER = 0x56;             // Timer prescaler value (lower 8 bits)
    case TIMER_RELOAD_HIGH = 0x58;           // Timer reload value MSB
    case TIMER_RELOAD_LOW = 0x5A;            // Timer reload value LSB
    case TIMER_COUNTER_HIGH = 0x5C;          // Timer counter value MSB
    case TIMER_COUNTER_LOW = 0x5E;           // Timer counter value LSB

    // Test and Version
    case TEST_SELECT_1 = 0x62;               // Test signal configuration 1
    case TEST_SELECT_2 = 0x64;               // Test signal configuration 2
    case TEST_PIN_ENABLE = 0x66;             // Pin output driver enable (D1-D7)
    case TEST_PIN_VALUE = 0x68;              // Pin values for I/O bus (D1-D7)
    case TEST_BUS = 0x6A;                    // Internal test bus status
    case AUTO_TEST = 0x6C;                   // Digital self-test control
    case VERSION = 0x6E;                     // Software version
    case ANALOG_TEST = 0x70;                 // AUX1 and AUX2 pin control
    case TEST_DAC_1 = 0x72;                  // Test DAC1 value
    case TEST_DAC_2 = 0x74;                  // Test DAC2 value
    case TEST_ADC = 0x76;                    // ADC I and Q channel values
}
