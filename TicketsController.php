<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\TicketsService;
use App\Transformers\TicketsTransformer;
use CloudVPN\Api\Services\Internal\UsersApiService;
use Dingo\Api\Http\Response;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketsController extends Controller
{
    /**
     * @param Request $request
     * @param UsersApiService $usersApiService
     * @return Response
     * @throws JsonException
     */
    public function list(Request $request, UsersApiService $usersApiService): Response
    {
        try {
            $networkUuid = $usersApiService->getCurrentNetworkInfo()['uuid'];
        } catch (GuzzleException $e) {
            abort(403);
        }

        $tickets = Ticket::network($networkUuid)
                         ->status($request->input('status'))
                         ->thematic($request->input('thematic'))
                         ->get()
                         ->sortByDesc('created_at');

        return $this->response->collection($tickets, new TicketsTransformer());
    }

    /**
     * @param TicketsService $ticketsService
     * @return JsonResponse
     * @throws JsonException
     */
    public function post(TicketsService $ticketsService): JsonResponse {
        $ticketsService->getAdditionalData()
                       ->uploadFiles()
                       ->reformatFields()
                       ->saveToDB()
                       ->dispatchSend();

        return new JsonResponse([], 201);
    }

    /**
     * @param UsersApiService $usersApiService
     * @param Cloud $fsManager
     * @param string $filename
     * @return StreamedResponse
     * @throws GuzzleException
     * @throws JsonException
     */
    public function download(UsersApiService $usersApiService, Cloud $fsManager, string $filename): StreamedResponse
    {
        $userInfo = $usersApiService->getCurrentUserInfo();

        return response()->stream(function() use ($fsManager, $userInfo, $filename) {
            $stream = $fsManager->readStream($userInfo['uuid'].'/'.$filename);
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Cache-Control'         => 'must-revalidate, post-check=0, pre-check=0',
            'Content-Type'          => $fsManager->mimeType($userInfo['uuid'].'/'.$filename),
            'Content-Length'        => $fsManager->size($userInfo['uuid'].'/'.$filename),
            'Content-Disposition'   => 'attachment; filename="' . basename($filename) . '"',
            'Pragma'                => 'public',
        ]);
    }
}
