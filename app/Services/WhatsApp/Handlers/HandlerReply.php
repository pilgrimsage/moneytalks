<?php

namespace App\Services\WhatsApp\Handlers;

/** What to send back: text, optionally with up to three reply buttons or a document to attach. */
final class HandlerReply
{
    /** @param list<array{id: string, title: string}> $buttons */
    public function __construct(
        public readonly string $text,
        public readonly array $buttons = [],
        /** @var array{path: string, filename: string, caption: ?string}|null a local temp file; the handler deletes it after sending */
        public readonly ?array $document = null,
    ) {}

    public static function text(string $text): self
    {
        return new self($text);
    }

    public function withPrefix(string $prefix): self
    {
        return new self($prefix."\n\n".$this->text, $this->buttons, $this->document);
    }

    public function withAppended(string $more): self
    {
        return new self(rtrim($this->text."\n\n".$more), $this->buttons, $this->document);
    }
}
