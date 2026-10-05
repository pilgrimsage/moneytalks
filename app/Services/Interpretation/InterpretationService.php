<?php

namespace App\Services\Interpretation;

use App\Models\User;
use App\Services\AI\AIGateway;
use App\Services\AI\ModelRouter;
use App\Services\AI\ResponseNormalizer;
use App\Services\Ops\AiSwitch;
use Carbon\CarbonImmutable;

/**
 * message -> prompt -> model -> validated decisions. The model's output is only ever a proposal;
 * `decisions` is what the application is actually willing to do.
 */
class InterpretationService
{
    public function __construct(
        private readonly AIGateway $gateway,
        private readonly ModelRouter $router,
        private readonly PromptContext $context,
        private readonly ProposalValidator $validator,
        private readonly AiSwitch $switch,
    ) {}

    public function interpret(User $user, string $text, ?string $whatsappMessageId = null, ?CarbonImmutable $now = null): InterpretationResult
    {
        $now ??= CarbonImmutable::now('UTC');

        if ($this->switch->blocked()) {
            return new InterpretationResult([], 'paused');
        }
        if ($this->gateway->overDailyLimit($user)) {
            return new InterpretationResult([], 'daily_limit');
        }

        $content = $this->context->build($user, $text, $now);
        $result = $this->attempt($user, $content, $text, $whatsappMessageId, $now, $this->router->primary('transaction_parser'));

        // Optional escalation (off by default): re-ask a stronger model when the first answer was unusable
        // or only produced "I'm not sure" questions.
        if ($this->shouldEscalate($result) && ($strong = $this->router->escalation('transaction_parser'))) {
            $result = $this->attempt($user, $content, $text, $whatsappMessageId, $now, $strong);
        }

        return $result;
    }

    private function attempt(User $user, string $content, string $text, ?string $messageId, CarbonImmutable $now, string $model): InterpretationResult
    {
        ['response' => $response, 'request_id' => $requestId] = $this->gateway->interpret($user, $content, $messageId, $model);

        $data = $response->isOk() ? ResponseNormalizer::normalize($response->data) : [];
        $error = ! $response->isOk() ? "model status {$response->status}" : $this->validator->structuralError($data);
        if ($error !== null) {
            $this->gateway->setOutcome($requestId, 'invalid_output');

            return new InterpretationResult([], 'invalid_output', $requestId, $response->model, $error);
        }

        $decisions = $this->validator->decide($user, $data, $text, $now);
        $this->gateway->setOutcome($requestId, $this->outcome($decisions));

        return new InterpretationResult($decisions, 'ok', $requestId, $response->model);
    }

    private function shouldEscalate(InterpretationResult $r): bool
    {
        if ($r->status === 'invalid_output') {
            return true;
        }

        return $r->decisions !== [] && collect($r->decisions)->every(fn (Decision $d) => $d->reason === 'low_confidence');
    }

    /** @param list<Decision> $decisions */
    private function outcome(array $decisions): string
    {
        $kinds = collect($decisions)->pluck('kind');

        return match (true) {
            $kinds->contains(Decision::RECORD) => 'record',
            $kinds->contains(Decision::CLARIFY) => 'clarify',
            $kinds->contains(Decision::UNSUPPORTED) => 'unsupported',
            default => 'help',
        };
    }
}
