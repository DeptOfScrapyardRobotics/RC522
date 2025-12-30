<?php

namespace ScrapyardIO\Sensors\RFID\RC522\Enums;

enum RC522Action: int
{
    // Chip Commands (written to COMMAND register)
    case IDLE = 0x00;                        // No action, cancel current command
    case STORE_TO_BUFFER = 0x01;             // Store 25 bytes into internal buffer
    case GENERATE_RANDOM_ID = 0x02;          // Generate 10-byte random ID
    case CALCULATE_CRC = 0x03;               // Activate CRC coprocessor
    case TRANSMIT = 0x04;                    // Transmit data from FIFO buffer
    case NO_COMMAND_CHANGE = 0x07;           // Modify command register bits only
    case RECEIVE = 0x08;                     // Activate receiver circuits
    case TRANSCEIVE = 0x0C;                  // Transmit and auto-activate receiver
    case MIFARE_AUTHENTICATE = 0x0E;         // Perform MIFARE authentication
    case SOFT_RESET = 0x0F;                  // Reset the chip

    // Card Commands (ISO 14443-3 Type A)
    case REQUEST_TYPE_A = 0x26;              // Request card presence (7-bit frame)
    case WAKEUP_TYPE_A = 0x52;               // Wake up card from halt (7-bit frame)
    case CASCADE_TAG = 0x88;                 // Cascade tag for anti-collision
    case SELECT_CASCADE_LEVEL_1 = 0x93;      // Anti-collision/Select Level 1
    case SELECT_CASCADE_LEVEL_2 = 0x95;      // Anti-collision/Select Level 2
    case SELECT_CASCADE_LEVEL_3 = 0x97;      // Anti-collision/Select Level 3
    case HALT = 0x50;                        // Halt card (enter sleep mode)
    case REQUEST_ATS = 0xE0;                 // Request Answer To Select

    // MIFARE Classic Commands
    case AUTH_WITH_KEY_A = 0x60;             // Authenticate using Key A
    case AUTH_WITH_KEY_B = 0x61;             // Authenticate using Key B
    case READ_BLOCK = 0x30;                  // Read 16-byte block
    case WRITE_BLOCK = 0xA0;                 // Write 16-byte block
    case DECREMENT_VALUE = 0xC0;             // Decrement value block
    case INCREMENT_VALUE = 0xC1;             // Increment value block
    case RESTORE_VALUE = 0xC2;               // Copy block to internal register
    case TRANSFER_VALUE = 0xB0;              // Write internal register to block

    // MIFARE Ultralight Commands
    case WRITE_PAGE = 0xA2;                  // Write 4-byte page
}
