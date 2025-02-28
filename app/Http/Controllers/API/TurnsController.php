<?php


namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Facades\Serializer;
use App\Http\Requests\ConversationObjectDuplicationRequest;
use App\Http\Requests\DeleteTurnRequest;
use App\Http\Requests\TurnIntentRequest;
use App\Http\Requests\TurnRequest;
use App\Http\Resources\IntentResource;
use App\Http\Resources\TurnIntentResource;
use App\Http\Resources\TurnIntentResourceCollection;
use App\Http\Resources\TurnResource;
use App\Rules\TurnInTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OpenDialogAi\ConversationEngine\Reasoners\IntentInterpreterFilter;
use OpenDialogAi\Core\Components\Configuration\ComponentConfigurationKey;
use OpenDialogAi\Core\Components\Configuration\ConfigurationDataHelper;
use OpenDialogAi\Core\Components\Exceptions\ConfigurationNotRegistered;
use OpenDialogAi\Core\Conversation\DataClients\Serializers\Normalizers\ImportExport\ScenarioNormalizer;
use OpenDialogAi\Core\Conversation\Facades\ConversationDataClient;
use OpenDialogAi\Core\Conversation\Facades\MessageTemplateDataClient;
use OpenDialogAi\Core\Conversation\Facades\TurnDataClient;
use OpenDialogAi\Core\Conversation\Intent;
use OpenDialogAi\Core\Conversation\MessageTemplate;
use OpenDialogAi\Core\Conversation\Transition;
use OpenDialogAi\Core\Conversation\Turn;
use OpenDialogAi\Core\ImportExportHelpers\Facades\ImportExportSerializer;
use OpenDialogAi\Core\ImportExportHelpers\PathSubstitutionHelper;
use OpenDialogAi\Core\ImportExportHelpers\ScenarioImportExportHelper;
use OpenDialogAi\Core\InterpreterEngine\Service\ConfiguredInterpreterServiceInterface;
use OpenDialogAi\InterpreterEngine\Interpreters\OpenDialogInterpreter;

class TurnsController extends Controller
{
    use ConversationObjectTrait;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Returns a collection of conversations for a particular scenario.
     *
     * @param Turn $turn
     * @return JsonResponse
     */
    public function showTurnIntentsByTurn(Turn $turn): JsonResponse
    {
        $requestIntents = ConversationDataClient::getAllRequestIntentsByTurn($turn, false);
        $responseIntents = ConversationDataClient::getAllResponseIntentsByTurn($turn, false);

        $turnIntents = [];
        foreach ($requestIntents as $intent) {
            $turnIntents[] = new TurnIntentResource($intent, 'REQUEST');
        }
        foreach ($responseIntents as $intent) {
            $turnIntents[] = new TurnIntentResource($intent, 'RESPONSE');
        }
        return response()->json(new TurnIntentResourceCollection($turnIntents));
    }

    /**
     * Store a newly created TurnIntent against a particular Turn.
     *
     * @param  Turn               $turn
     * @param  TurnIntentRequest  $request
     *
     * @return TurnIntentResource|JsonResponse
     */
    public function storeTurnIntentAgainstTurn(Turn $turn, TurnIntentRequest $request)
    {
        /** @var Intent $newIntent */
        $newIntent = Serializer::denormalize($request->get('intent'), Intent::class, 'json');
        $newIntent->setTurn($turn);

        if ($request->get('order') === 'REQUEST') {
            $newIntent->setOrder($turn->getRequestIntents()->getNextOrderNumber());
            $savedIntent = ConversationDataClient::addRequestIntent($newIntent);
        } else {
            $newIntent->setOrder($turn->getResponseIntents()->getNextOrderNumber());
            $savedIntent = ConversationDataClient::addResponseIntent($newIntent);
        }

        $this->createMessageTemplate($savedIntent);

        $resource = new TurnIntentResource($savedIntent, $request->get('order'));

        /** @var Turn $originalTurn */
        $originalTurn = ConversationDataClient::getScenarioWithFocusedTurn($turn->getUid());

        return $this->prepareODHeaders($originalTurn, $savedIntent, $resource);
    }

    /**
     * Display the specified Turn.
     *
     * @param Turn $turn
     * @return TurnResource
     */
    public function show(Turn $turn): TurnResource
    {
        return new TurnResource($turn);
    }

    /**
     * Update the specified TurnRequest.
     *
     * @param TurnRequest $request
     * @param Turn $turn
     * @return TurnResource
     */
    public function update(TurnRequest $request, Turn $turn): TurnResource
    {
        $turnUpdate = Serializer::deserialize($request->getContent(), Turn::class, 'json');
        $updatedTurn = ConversationDataClient::updateTurn($turnUpdate);
        return new TurnResource($updatedTurn);
    }

    /**
     * @param DeleteTurnRequest $request
     * @param Turn $Turn
     * @return Response $response
     */
    public function destroy(DeleteTurnRequest $request, Turn $Turn): Response
    {
        if ($request->json('force')) {
            $linkedIntents = TurnInTransition::getIntentsThatTransitionTo($Turn->getUid());

            $linkedIntents->each(function (Intent $intent) {
                $intent->setTransition(new Transition(null, null, null));
                ConversationDataClient::updateIntent($intent);
            });
        }

        if (ConversationDataClient::deleteTurnByUid($Turn->getUid())) {
            return response()->noContent(200);
        } else {
            return response('Error deleting conversation, check the logs', 500);
        }
    }

    /**
     *
     * @param Turn $turn
     * @param Intent $intent
     * @return TurnIntentResource $response
     */
    public function getTurnIntentByTurnAndIntent(Turn $turn, Intent $intent) : TurnIntentResource
    {
        $turnWithIntent = ConversationDataClient::getTurnWithIntent($turn->getUid(), $intent->getUid());
        if ($turnWithIntent->getRequestIntents()->count() > 0) {
            return new TurnIntentResource($turnWithIntent->getRequestIntents()->first(), 'REQUEST');
        }

        return new TurnIntentResource($turnWithIntent->getResponseIntents()->first(), 'RESPONSE');
    }


    /**
     * @param TurnIntentRequest $request
     * @param Turn $turn
     * @param Intent $intent
     * @return JsonResponse|JsonResource
     */
    public function updateTurnIntent(TurnIntentRequest $request, Turn $turn, Intent $intent)
    {
        $patchIntent = Serializer::denormalize($request->get('intent'), Intent::class, 'json');
        $patchIntent->setUid($intent->getUid());
        $patchIntent->setTurn($turn);

        // First update the intent data
        $updatedIntent = ConversationDataClient::updateIntent($patchIntent);
        $updatedTurnWithIntent = ConversationDataClient::updateTurnIntentRelation(
            $updatedIntent->getTurn()->getUid(),
            $updatedIntent->getUid(),
            $request->get('order')
        );

        if ($updatedTurnWithIntent->getRequestIntents()->count() > 0) {
            $resource = new TurnIntentResource($updatedTurnWithIntent->getRequestIntents()->first(), 'REQUEST');
        } elseif ($updatedTurnWithIntent->getResponseIntents()->count() > 0) {
            $resource = new TurnIntentResource($updatedTurnWithIntent->getResponseIntents()->first(), 'RESPONSE');
        }

        $originalTurn = ConversationDataClient::getScenarioWithFocusedTurn($turn->getUid());

        return $this->prepareODHeaders($originalTurn, $updatedIntent, $resource);
    }

    public function destroyTurnIntent(Turn $turn, Intent $intent)
    {
        $intent = ConversationDataClient::deleteIntentByUid($intent->getUid());

        $resource = new IntentResource($intent);

        $originalTurn = ConversationDataClient::getScenarioWithFocusedTurn($turn->getUid());

        return $this->prepareODHeaders($originalTurn, $intent, $resource);
    }

    /**
     * Creates an intent and message template in the Response Engine if the intent being created is from the APP participant
     *
     * @param Intent $intent
     */
    private function createMessageTemplate(Intent $intent)
    {
        if ($intent->getSpeaker() === 'APP') {
            Log::info(
                sprintf('Creating a new intent and message template for intent %s as the speaker was APP', $intent->getName())
            );

            $sampleUtterance = $intent->getSampleUtterance();

            // Ensure the intent has a full interpreter hierarchy
            $turn = ConversationDataClient::getScenarioWithFocusedTurn($intent->getTurn()->getUid());
            $intent->setTurn($turn);

            $interpreterName = IntentInterpreterFilter::getInterpreter($intent);

            if (is_null($interpreterName) || $interpreterName === '') {
                $interpreterName = ConfigurationDataHelper::OPENDIALOG_INTERPRETER;
            }

            $scenarioUid = $intent->getScenario()->getUid();
            $key = new ComponentConfigurationKey($scenarioUid, $interpreterName);

            /** @var ConfiguredInterpreterServiceInterface $service */
            $service = resolve(ConfiguredInterpreterServiceInterface::class);

            try {
                $interpreter = $service->get($key);
                $messageMarkup = $interpreter->getDefaultMessageMarkup($sampleUtterance);
            } catch (ConfigurationNotRegistered $e) {
                $messageMarkup = OpenDialogInterpreter::getDefaultMessageMarkup($sampleUtterance);
            }

            $messageTemplate = new MessageTemplate();
            $messageTemplate->setName($intent->getName());
            $messageTemplate->setOdId($intent->getOdId());
            $messageTemplate->setIntent($intent);
            $messageTemplate->setMessageMarkup($messageMarkup);
            $messageTemplate->setOrder(0);

            MessageTemplateDataClient::addMessageTemplateToIntent($messageTemplate);
        } else {
            Log::debug(
                sprintf(
                    'Not creating a new intent and message template for intent %s as the speaker was USER',
                    $intent->getName()
                )
            );
        }
    }

    /**
     * @param ConversationObjectDuplicationRequest $request
     * @param Turn $turn
     * @return TurnResource
     */
    public function duplicate(ConversationObjectDuplicationRequest $request, Turn $turn): TurnResource
    {
        $turn = TurnDataClient::getFullTurnGraph($turn->getUid());

        $scene = ConversationDataClient::getSceneByUid($turn->getScene()->getUid());

        /** @var Turn $turn */
        $turn = $request->setUniqueOdId($turn, $request, $scene);

        $map = PathSubstitutionHelper::createTurnMap($turn, '_duplicate', '_duplicate', '_duplicate');

        $turn->removeUid();

        // Serialize, then deserialize the turn to convert the UID references to paths
        $serialized = ImportExportSerializer::serialize($turn, 'json', [
            ScenarioNormalizer::UID_MAP => $map
        ]);

        /** @var Turn $turn */
        $turn = ImportExportSerializer::deserialize($serialized, Turn::class, 'json');

        $turn->setScene($scene);

        $turn->setCreatedAt(Carbon::now());
        $turn->setUpdatedAt(Carbon::now());

        $duplicate = TurnDataClient::addFullTurnGraph($turn);
        $duplicate = TurnDataClient::getFullTurnGraph($duplicate->getUid());

        // Create a new map of all new UIDs to/from paths
        $map = PathSubstitutionHelper::createTurnMap($duplicate, '_duplicate', '_duplicate', '_duplicate');

        // Serialize the duplicate, then deserializing using the map to replace the paths with new UIDs
        $serialized = ImportExportSerializer::serialize($duplicate, 'json');

        /** @var Turn $turnWithPathsSubstituted */
        $turnWithPathsSubstituted = ImportExportSerializer::deserialize($serialized, Turn::class, 'json', [
            ScenarioNormalizer::UID_MAP => $map
        ]);

        ScenarioImportExportHelper::patchTurn($duplicate, $turnWithPathsSubstituted);
        $duplicate = TurnDataClient::getFullTurnGraph($duplicate->getUid());

        return new TurnResource($duplicate);
    }
}
