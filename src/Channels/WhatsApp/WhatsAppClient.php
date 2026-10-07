<?php

namespace Nugsoft\SignalBridge\Channels\WhatsApp;

use Nugsoft\SignalBridge\Channels\BaseChannelClient;
use Nugsoft\SignalBridge\Exceptions\ValidationException;

/**
 * WhatsApp through SignalBridge.
 *
 * WhatsApp only lets a business start a conversation with a template it has
 * approved, so the flow is: createTemplate() once, wait for the
 * template.approved webhook, then sendTemplate() as often as needed. Free
 * text (send()) and Flows (sendFlow()) are delivered only within 24 hours of
 * the person's last message. SignalBridge holds every WhatsApp credential and
 * handles Flow encryption — nothing here needs Meta access.
 */
class WhatsAppClient extends BaseChannelClient
{
    /**
     * Send free text. WhatsApp delivers it only within 24 hours of the
     * person's last message; to start a conversation use sendTemplate().
     *
     * @param  array<string, mixed>  $options  metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function send(string $recipient, string $message, array $options = []): array
    {
        $this->requireRecipient($recipient);

        if (trim($message) === '') {
            throw new ValidationException('Message content is required');
        }

        if (mb_strlen($message) > 4096) {
            throw new ValidationException('WhatsApp message exceeds maximum length of 4096 characters');
        }

        return $this->request('POST', 'whatsapp/send', ['json' => ['recipient' => $recipient, 'message' => $message] + $this->common($options)]);
    }

    /**
     * Send one of your approved templates.
     *
     * @param  list<string>  $variables  Values for {{1}}, {{2}}, … in order (the code, for an authentication template)
     * @param  array<string, mixed>  $options  language, header (['type' => 'document'|'image'|'video', 'url' => …, 'filename' => …]),
     *                                         flow (['data' => […]] for a template with a Flow button), metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function sendTemplate(string $recipient, string $templateName, array $variables = [], array $options = []): array
    {
        $this->requireRecipient($recipient);

        if (trim($templateName) === '') {
            throw new ValidationException('Template name is required');
        }

        foreach ($variables as $value) {
            if (is_array($value)) {
                throw new ValidationException(
                    'sendTemplate() takes the variables as a plain list, e.g. [\'John\', \'UGX 50,000\']. '
                    .'The Meta "components" structure is built by SignalBridge.'
                );
            }
        }

        return $this->request('POST', 'whatsapp/send', ['json' => array_filter([
            'recipient' => $recipient,
            'template' => $templateName,
            'variables' => array_values(array_map('strval', $variables)),
            'language' => $options['language'] ?? null,
            'header' => $options['header'] ?? null,
            'flow' => $options['flow'] ?? null,
        ], fn ($value) => $value !== null) + $this->common($options)]);
    }

    /**
     * Send one of your published Flows as an interactive message (within 24
     * hours of the person's last message). The answers arrive as a
     * flow.completed webhook.
     *
     * @param  array<string, mixed>  $options  header, footer, screen, data, metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function sendFlow(string $recipient, string $flowName, string $body, string $button, array $options = []): array
    {
        $this->requireRecipient($recipient);

        return $this->request('POST', 'whatsapp/send', ['json' => [
            'recipient' => $recipient,
            'flow' => array_filter([
                'name' => $flowName,
                'body' => $body,
                'button' => $button,
                'header' => $options['header'] ?? null,
                'footer' => $options['footer'] ?? null,
                'screen' => $options['screen'] ?? null,
                'data' => $options['data'] ?? null,
            ], fn ($value) => $value !== null),
        ] + $this->common($options)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function listTemplates(?string $status = null): array
    {
        return $this->request('GET', 'whatsapp/templates', ['query' => array_filter(['status' => $status])]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplate(int $templateId, bool $refresh = false): array
    {
        return $this->request('GET', "whatsapp/templates/{$templateId}", ['query' => $refresh ? ['refresh' => 1] : []]);
    }

    /**
     * Submit a template for WhatsApp's review.
     *
     * @param  array<string, mixed>  $template  name, category, language, body, examples, header, footer, buttons
     *                                          — or, for authentication, code_expiration_minutes
     * @return array<string, mixed>
     */
    public function createTemplate(array $template): array
    {
        return $this->request('POST', 'whatsapp/templates', ['json' => $template]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteTemplate(int $templateId): array
    {
        return $this->request('DELETE', "whatsapp/templates/{$templateId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function listFlows(): array
    {
        return $this->request('GET', 'whatsapp/flows');
    }

    /**
     * @return array<string, mixed>
     */
    public function getFlow(int $flowId, bool $refresh = false): array
    {
        return $this->request('GET', "whatsapp/flows/{$flowId}", ['query' => $refresh ? ['refresh' => 1] : []]);
    }

    /**
     * Create a Flow as a draft from its JSON. Give endpoint_url if it fetches
     * live data: SignalBridge forwards those calls there as plain JSON, signed
     * with the endpoint_secret in the response (shown once).
     *
     * @param  array<string, mixed>  $flow  name, categories, flow_json (array or string), endpoint_url
     * @return array<string, mixed>
     */
    public function createFlow(array $flow): array
    {
        return $this->request('POST', 'whatsapp/flows', ['json' => $flow]);
    }

    /**
     * @param  array<string, mixed>  $changes  flow_json (drafts only), endpoint_url
     * @return array<string, mixed>
     */
    public function updateFlow(int $flowId, array $changes): array
    {
        return $this->request('PUT', "whatsapp/flows/{$flowId}", ['json' => $changes]);
    }

    /**
     * @return array<string, mixed>
     */
    public function publishFlow(int $flowId): array
    {
        return $this->request('POST', "whatsapp/flows/{$flowId}/publish");
    }

    /**
     * @return array<string, mixed>
     */
    public function regenerateFlowSecret(int $flowId): array
    {
        return $this->request('POST', "whatsapp/flows/{$flowId}/regenerate-secret");
    }

    /**
     * Delete a draft, or retire a published Flow.
     *
     * @return array<string, mixed>
     */
    public function deleteFlow(int $flowId): array
    {
        return $this->request('DELETE', "whatsapp/flows/{$flowId}");
    }

    /**
     * Messages customers sent you, newest first.
     *
     * @param  array<string, mixed>  $filters  from, type, since, per_page, page
     * @return array<string, mixed>
     */
    public function received(array $filters = []): array
    {
        return $this->request('GET', 'whatsapp/received', ['query' => $filters]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getReceived(int $messageId): array
    {
        return $this->request('GET', "whatsapp/received/{$messageId}");
    }

    /**
     * The file a customer sent (photo, document, voice note, video), as raw bytes.
     */
    public function downloadMedia(int $messageId): string
    {
        return $this->requestBody('GET', "whatsapp/received/{$messageId}/media");
    }

    protected function requireRecipient(string $recipient): void
    {
        if (trim($recipient) === '') {
            throw new ValidationException('Recipient phone number is required');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function common(array $options): array
    {
        return array_filter([
            'metadata' => $options['metadata'] ?? null,
            'scheduled_at' => $options['scheduled_at'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
