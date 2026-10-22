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
 * text (send()), files (sendMedia()), reply buttons, lists and link buttons
 * (sendButtons(), sendList(), sendLinkButton()) and Flows (sendFlow()) are
 * delivered only within 24 hours of the person's last message. SignalBridge holds every WhatsApp credential and
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
     * The files WhatsApp sends as a message of their own.
     */
    public const MEDIA_TYPES = ['image', 'document', 'video', 'audio', 'sticker'];

    /**
     * Send a file on its own: an image, document, video, audio (a voice note,
     * say) or sticker. WhatsApp downloads it from the https link, and
     * delivers it only within 24 hours of the person's last message.
     *
     * @param  array<string, mixed>  $options  caption (image, video, document), filename (document), metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function sendMedia(string $recipient, string $type, string $url, array $options = []): array
    {
        $this->requireRecipient($recipient);

        if (! in_array($type, self::MEDIA_TYPES, true)) {
            throw new ValidationException('The media type must be one of: '.implode(', ', self::MEDIA_TYPES));
        }

        if (! str_starts_with($url, 'https://')) {
            throw new ValidationException('The media URL must be a public https link WhatsApp can download the file from');
        }

        return $this->request('POST', 'whatsapp/send', ['json' => [
            'recipient' => $recipient,
            'media' => array_filter([
                'type' => $type,
                'url' => $url,
                'caption' => $options['caption'] ?? null,
                'filename' => $options['filename'] ?? null,
            ], fn ($value) => $value !== null),
        ] + $this->common($options)]);
    }

    /**
     * Send up to three reply buttons (within 24 hours of the person's last
     * message). A tap arrives as a message.received webhook whose
     * content.reply_id is the button's id.
     *
     * @param  array<string|int, mixed>  $buttons  ['confirm_4821' => 'Confirm', …], or [['id' => …, 'title' => …], …]
     * @param  array<string, mixed>  $options  header (text, or ['type' => 'image'|'video'|'document', 'url' => …]), footer, metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function sendButtons(string $recipient, string $body, array $buttons, array $options = []): array
    {
        $this->requireRecipient($recipient);

        $buttons = array_map(
            fn ($title, $id) => is_array($title) ? $title : ['id' => (string) $id, 'title' => (string) $title],
            $buttons,
            array_keys($buttons)
        );

        if ($buttons === [] || count($buttons) > 3) {
            throw new ValidationException('WhatsApp allows 1 to 3 reply buttons. Use sendList() for more options.');
        }

        return $this->sendInteractive($recipient, ['type' => 'buttons', 'body' => $body, 'buttons' => $buttons], $options);
    }

    /**
     * Send a list: one button that opens up to 10 options, in sections
     * (within 24 hours of the person's last message). A pick arrives as a
     * message.received webhook whose content.reply_id is the option's id.
     *
     * @param  list<array<string, mixed>>  $sections  [['title' => 'Kampala', 'rows' => [['id' => …, 'title' => …, 'description' => …], …]], …]
     * @param  array<string, mixed>  $options  header (text only), footer, metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function sendList(string $recipient, string $body, string $button, array $sections, array $options = []): array
    {
        $this->requireRecipient($recipient);

        $rows = array_sum(array_map(fn ($section) => count($section['rows'] ?? []), $sections));

        if ($rows === 0 || $rows > 10) {
            throw new ValidationException('A WhatsApp list has 1 to 10 options across all its sections.');
        }

        return $this->sendInteractive($recipient, ['type' => 'list', 'body' => $body, 'button' => $button, 'sections' => $sections], $options);
    }

    /**
     * Send a button that opens a link, without showing the URL (within 24
     * hours of the person's last message).
     *
     * @param  array<string, mixed>  $options  header (text, or ['type' => 'image'|'video'|'document', 'url' => …]), footer, metadata, scheduled_at
     * @return array<string, mixed>
     */
    public function sendLinkButton(string $recipient, string $body, string $button, string $url, array $options = []): array
    {
        $this->requireRecipient($recipient);

        if (! str_starts_with($url, 'https://')) {
            throw new ValidationException('The link must be an https URL');
        }

        return $this->sendInteractive($recipient, ['type' => 'cta_url', 'body' => $body, 'button' => $button, 'url' => $url], $options);
    }

    /**
     * @param  array<string, mixed>  $interactive
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function sendInteractive(string $recipient, array $interactive, array $options): array
    {
        $header = $options['header'] ?? null;

        return $this->request('POST', 'whatsapp/send', ['json' => [
            'recipient' => $recipient,
            'interactive' => array_filter([
                ...$interactive,
                'header' => is_string($header) ? ['type' => 'text', 'text' => $header] : $header,
                'footer' => $options['footer'] ?? null,
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
