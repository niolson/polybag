<?php

namespace App\Services;

use App\Http\Integrations\Gotenberg\GotenbergConnector;
use App\Http\Integrations\Gotenberg\Requests\HtmlToPdfRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

class GotenbergService
{
    /**
     * What a person is told when a PDF cannot be made. The cause is logged:
     * the service's name and its HTTP error mean nothing at a packing bench.
     */
    public const string UNAVAILABLE = 'The PDF could not be made. Try again; if it keeps failing, ask your administrator to check the PDF service.';

    public function __construct(private readonly GotenbergConnector $connector) {}

    /**
     * Render a Blade view to a PDF and return the raw PDF bytes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException if Gotenberg is not configured or the request fails
     */
    public function pdfFromView(string $view, array $data = []): string
    {
        $html = view($view, $data)->render();

        try {
            $response = $this->connector->send(new HtmlToPdfRequest($html));
            $response->throw();
        } catch (FatalRequestException $e) {
            logger()->error('PDF renderer unreachable', ['error' => $e->getMessage()]);

            throw new \RuntimeException(self::UNAVAILABLE, previous: $e);
        } catch (RequestException $e) {
            logger()->error('PDF renderer returned an error', ['status' => $e->getResponse()->status(), 'error' => $e->getMessage()]);

            throw new \RuntimeException(self::UNAVAILABLE, previous: $e);
        }

        return $response->body();
    }
}
