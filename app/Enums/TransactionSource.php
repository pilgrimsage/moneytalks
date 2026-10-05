<?php

namespace App\Enums;

enum TransactionSource: string
{
    case WhatsappText = 'whatsapp_text';
    case WhatsappVoice = 'whatsapp_voice';
    case WhatsappImage = 'whatsapp_image';
    case Manual = 'manual';
    case Import = 'import';
    case System = 'system';
}
