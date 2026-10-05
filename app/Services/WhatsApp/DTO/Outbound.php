<?php

namespace App\Services\WhatsApp\DTO;

use InvalidArgumentException;

/** An outbound message. Build with the named constructors; they validate WhatsApp's limits. */
final class Outbound
{
    public const MAX_BUTTONS = 3;

    public const MAX_BUTTON_TITLE = 20;

    private function __construct(
        public readonly string $kind,            // text | buttons | template
        public readonly string $to,
        public readonly ?string $body = null,
        /** @var list<array{id: string, title: string}> */
        public readonly array $buttons = [],
        public readonly ?string $templateName = null,
        public readonly string $language = 'en',
        public readonly array $templateComponents = [],
        public readonly ?string $filePath = null,
        public readonly ?string $filename = null,
        public readonly string $mimeType = 'text/csv',
    ) {}

    public static function text(string $to, string $body): self
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Message body is empty.');
        }

        return new self('text', $to, $body);
    }

    /** @param list<array{id: string, title: string}> $buttons */
    public static function buttons(string $to, string $body, array $buttons): self
    {
        if ($buttons === [] || count($buttons) > self::MAX_BUTTONS) {
            throw new InvalidArgumentException('WhatsApp reply buttons: 1 to '.self::MAX_BUTTONS.' allowed.');
        }
        foreach ($buttons as $b) {
            if (mb_strlen($b['title'], 'UTF-8') > self::MAX_BUTTON_TITLE || trim($b['title']) === '' || $b['id'] === '') {
                throw new InvalidArgumentException('Button needs an id and a title of at most '.self::MAX_BUTTON_TITLE.' characters.');
            }
        }

        return new self('buttons', $to, $body, $buttons);
    }

    public static function template(string $to, string $name, string $language = 'en', array $components = []): self
    {
        return new self('template', $to, null, [], $name, $language, $components);
    }

    /** A file to send as a document (e.g. a CSV export). The path must be a readable local file. */
    public static function document(string $to, string $filePath, string $filename, ?string $caption = null, string $mimeType = 'text/csv'): self
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException('Document file is not readable.');
        }

        return new self('document', $to, $caption, [], null, 'en', [], $filePath, $filename, $mimeType);
    }

    public function isFreeForm(): bool
    {
        return $this->kind !== 'template';
    }

    public function withBody(string $body): self
    {
        return new self($this->kind, $this->to, $body, $this->buttons, $this->templateName, $this->language, $this->templateComponents, $this->filePath, $this->filename, $this->mimeType);
    }
}
