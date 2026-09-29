<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;

/** Packeta's XML/REST API. Secrets are used only in server-side requests. */
final class PacketaApiClient
{
    private const ENDPOINT = 'https://www.zasilkovna.cz/api/rest';

    /** @param null|callable(string,string):array{status:int,body:string} $transport */
    public function __construct(private string $password, private $transport = null)
    {
        if ($password === '' || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Podání vyžaduje API heslo a rozšíření PHP SimpleXML.');
        }
    }

    /** @return array{id:string,barcode:string,barcode_text:string} */
    public function createPacket(array $attributes): array
    {
        $result = $this->call('createPacket', ['packetAttributes' => $attributes]);
        $id = trim((string) ($result->id ?? ''));
        $barcode = trim((string) ($result->barcode ?? ''));
        $barcodeText = trim((string) ($result->barcodeText ?? ''));
        if (preg_match('/^[0-9]{1,20}$/D', $id) !== 1 ||
            preg_match('/^Z[0-9]{1,20}$/D', $barcode) !== 1 ||
            $barcode !== 'Z' . $id) {
            throw new RuntimeException('Zásilkovna vrátila neúplné číslo zásilky. Zkontroluj ji v klientské sekci.');
        }
        return ['id' => $id, 'barcode' => $barcode,
            'barcode_text' => $barcodeText !== '' && strlen($barcodeText) <= 120 ? $barcodeText : $barcode];
    }

    public function courierNumber(string $barcode): string
    {
        self::assertBarcode($barcode);
        $number = trim((string) $this->call('packetCourierNumber', ['packetId' => $barcode]));
        if ($number === '' || strlen($number) > 100 || preg_match('/[\x00-\x1f\x7f]/', $number)) {
            throw new RuntimeException('Zásilkovna nevrátila číslo dopravce.');
        }
        return $number;
    }

    public function labelPdf(string $barcode, ?string $courierNumber = null): string
    {
        self::assertBarcode($barcode);
        if ($courierNumber === null) {
            $result = $this->call('packetLabelPdf', ['packetId' => $barcode,
                'format' => 'A6 on A6', 'offset' => '0']);
        } else {
            if ($courierNumber === '' || strlen($courierNumber) > 100 ||
                preg_match('/[\x00-\x1f\x7f]/', $courierNumber)) {
                throw new InvalidArgumentException('Neplatné číslo dopravce.');
            }
            $result = $this->call('packetCourierLabelPdf', ['packetId' => $barcode,
                'courierNumber' => $courierNumber]);
        }
        $encoded = trim((string) $result);
        if (strlen($encoded) > 14000000) {
            throw new RuntimeException('Štítek Zásilkovny je příliš velký.');
        }
        $pdf = base64_decode($encoded, true);
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-') || strlen($pdf) > 10485760) {
            throw new RuntimeException('Zásilkovna nevrátila platný PDF štítek.');
        }
        return $pdf;
    }

    private static function assertBarcode(string $barcode): void
    {
        if (preg_match('/^Z[0-9]{1,20}$/D', $barcode) !== 1) {
            throw new InvalidArgumentException('Neplatné číslo zásilky.');
        }
    }

    private function call(string $method, array $fields): SimpleXMLElement
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><' . $method . '>' .
            self::element('apiPassword', $this->password);
        foreach ($fields as $name => $value) {
            if ($name === 'packetAttributes') {
                $xml .= '<packetAttributes>';
                foreach ($value as $field => $item) $xml .= self::element($field, (string) $item);
                $xml .= '</packetAttributes>';
            } else {
                $xml .= self::element($name, (string) $value);
            }
        }
        $xml .= '</' . $method . '>';
        $response = $this->transport !== null ? ($this->transport)(self::ENDPOINT, $xml) : self::post($xml);
        if (!is_array($response) || !in_array($response['status'] ?? null, [200, 400, 401, 403, 422], true) ||
            !is_string($response['body'] ?? null) || strlen($response['body']) > 14000000 ||
            str_contains($response['body'], '<!DOCTYPE') || str_contains($response['body'], '<!ENTITY')) {
            throw new RuntimeException('Odpověď Zásilkovny není dostupná nebo není platná. Zkontroluj zásilku v klientské sekci.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($response['body'], SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($parsed === false || $parsed->getName() !== 'response') {
            throw new RuntimeException('Zásilkovna vrátila nečitelnou odpověď. Zkontroluj zásilku v klientské sekci.');
        }
        if ((string) $parsed->status === 'fault') {
            $message = trim((string) ($parsed->fault->string ?? $parsed->fault->message ?? ''));
            $message = preg_replace('/[\x00-\x1f\x7f]/', ' ', $message) ?? '';
            $message = str_replace($this->password, '[skryto]', $message);
            throw new PacketaRejectedException('Zásilkovna odmítla požadavek: ' .
                ($message !== '' && strlen($message) <= 300 ? $message : 'zkontroluj údaje a nastavení odesílatele.'));
        }
        if ((string) $parsed->status !== 'ok' || !isset($parsed->result)) {
            throw new RuntimeException('Zásilkovna nepotvrdila výsledek. Zkontroluj zásilku v klientské sekci.');
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('Zásilkovna nepotvrdila HTTP požadavek. Zkontroluj zásilku v klientské sekci.');
        }
        return $parsed->result;
    }

    private static function element(string $name, string $value): string
    {
        // Names are fixed by our own callers; customer input is always escaped.
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Neplatný údaj zásilky.');
        }
        return '<' . $name . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . '>';
    }

    /** Bounded HTTPS request with redirects disabled. */
    private static function post(string $xml): array
    {
        $headers = ['Content-Type: application/xml; charset=UTF-8', 'Accept: application/xml'];
        if (function_exists('curl_init')) {
            $curl = curl_init(self::ENDPOINT);
            if ($curl === false) return ['status' => 0, 'body' => ''];
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
        }
        $context = stream_context_create(['http' => ['method' => 'POST',
            'header' => implode("\r\n", $headers), 'content' => $xml,
            'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents(self::ENDPOINT, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $header, $matches)) $status = (int) $matches[1];
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}
