<?php declare(strict_types=1);

namespace MauticPlugin\MauticMultiCaptchaBundle\Controller;

use \Exception;
use \RuntimeException;

use MauticPlugin\MauticMultiCaptchaBundle\Service\AltchaClient;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * <h1>Class AltchaApiController</h1>
 *   Simple API Controller for ALTCHA challenge generation
 *
 * @package MauticPlugin\MauticMultiCaptchaBundle\Controller
 *
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
class AltchaApiController {

    /**
     * <h2>AltchaApiController constructor.</h2>
     *
     * @param AltchaClient $altchaClient
     */
    public function __construct(private readonly AltchaClient $altchaClient) {
        // silent constructor
    }

    /**
     * <h2>generateChallengeAction</h2>
     *   API endpoint to generate a fresh ALTCHA challenge as JSON
     *
     *   Uses configuration values from global ALTCHA settings with fallback defaults
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function generateChallengeAction(Request $request): JsonResponse {
        // Handle CORS preflight requests
        if ($request->getMethod() === "OPTIONS") {
            $response = new JsonResponse();

            $response->headers->set("Access-Control-Allow-Origin", "*");
            $response->headers->set("Access-Control-Allow-Methods", "GET, OPTIONS");
            $response->headers->set("Access-Control-Allow-Headers", "Content-Type, X-Requested-With, X-Altcha-Spam-Filter, Cache-Control");
            $response->headers->set("Access-Control-Max-Age", "86400");

            $response->setStatusCode(204);

            return $response;
        }
        
        try {
            $config = $this->altchaClient->getConfiguration();

            $maxNumber = $config["maxNumber"] ?? 50000;
            $expires   = $config["expires"] ?? 120;
            
            $challengeData = $this->altchaClient->createChallenge($maxNumber, $expires);
            
            if(empty($challengeData))
                return new JsonResponse([
                    "error" => "Failed to generate challenge"
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            $response = new JsonResponse($challengeData);
            
            $response->headers->set("Access-Control-Allow-Origin", "*");
            $response->headers->set("Access-Control-Allow-Methods", "GET, OPTIONS");
            $response->headers->set("Access-Control-Allow-Headers", "Content-Type, X-Requested-With, X-Altcha-Spam-Filter, Cache-Control");

            return $response;
        } catch(RuntimeException $e) {
            $response = new JsonResponse([
                "error" => "ALTCHA not configured: {$e->getMessage()}"
            ], Response::HTTP_SERVICE_UNAVAILABLE);
            
            $response->headers->set("Access-Control-Allow-Origin", "*");
            $response->headers->set("Access-Control-Allow-Methods", "GET, OPTIONS");
            $response->headers->set("Access-Control-Allow-Headers", "Content-Type, X-Requested-With, X-Altcha-Spam-Filter, Cache-Control");

            return $response;
        } catch(Exception $e) {
            $response = new JsonResponse([
                "error" => "Internal server error"
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
            
            $response->headers->set("Access-Control-Allow-Origin", "*");
            $response->headers->set("Access-Control-Allow-Methods", "GET, OPTIONS");
            $response->headers->set("Access-Control-Allow-Headers", "Content-Type, X-Requested-With, X-Altcha-Spam-Filter, Cache-Control");

            return $response;
        }
    }

}
