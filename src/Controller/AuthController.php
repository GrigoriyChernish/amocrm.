<?php
namespace App\Controller;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\Collections\TasksCollection;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\CompanyModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\TaskModel;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    private $session;

    public function __construct(SessionInterface $session)
    {

        $this->session = $session;
    }
    /**
     * @Route("/auth/")
     */
    public function index(): RedirectResponse
    {

        if (empty($this->session->get('token'))) {

            if (isset($_GET['referer'])) {
                $apiClient->setAccountBaseDomain($_GET['referer']);
            }

            if (!isset($_GET['code'])) {
                if (isset($_GET['button'])) {
                    echo $apiClient->getOAuthClient()->getOAuthButton(
                        [
                            'title' => 'Установить интеграцию',
                            'compact' => true,
                            'class_name' => 'className',
                            'color' => 'default',
                            'error_callback' => 'handleOauthError',
                            'state' => $this->session->get('oauth2state'),
                        ]
                    );
                    die;
                } else {
                    $authorizationUrl = $apiClient->getOAuthClient()->getAuthorizeUrl([
                        'state' => $this->session->get('oauth2state'),
                        'mode' => 'post_message',
                    ]);
                    header('Location: ' . $authorizationUrl);
                    die;
                }
            } elseif (empty($_GET['state']) || empty($this->session->get('oauth2state')) || ($_GET['state'] !== $this->session->get('oauth2state'))) {

                exit('Invalid state');
            }

            try {
                $accessToken = $apiClient->getOAuthClient()->getAccessTokenByCode($_GET['code']);

                if (!$accessToken->hasExpired()) {
                    $this->session->set('token', ['accessToken' => $accessToken->getToken(),
                        'refreshToken' => $accessToken->getRefreshToken(),
                        'expires' => $accessToken->getExpires(),
                        'baseDomain' => $apiClient->getAccountBaseDomain()]
                    );
                    return $this->redirect('/amocrm');
                }
            } catch (Exception $e) {
                die((string) $e);
            }
        } else {

            return $this->redirect('/amocrm');
        }

    }

    /**
     * @Route("amocrm")
     */
    public function amocCrm()
    {

        $apiClient = $this->apiClient();

        $leads = $this->getLeadsAll($apiClient);

        return $this->render('leads.html.twig', ['leads' => $leads]);
    }
/**
 * @Route("create")
 */
    public function create()
    {

        //$start_of_day = time() - 86400 + (time() % 86400);
        //$end_of_day = $start_of_day + 86400;

        $begin = date('00:00:00');
        $end = date('23:59:59', strtotime($begin));
        $end_of_day = date(strtotime($end));

        $apiClient = $this->apiClient();

        $leadsService = $apiClient->leads();
        $tasksService = $apiClient->tasks();

        $responsibleUserId = $apiClient->users()->get()[0]->id;

        $lead = new LeadModel();
        $lead->setName('Тестовая задача')

            ->setResponsibleUserId($responsibleUserId)
            ->setContacts(
                (new ContactsCollection())
                    ->add(
                        (new ContactModel())
                            ->setId(8349123)
                    )
                    ->add(
                        (new ContactModel())
                            ->setId(8348759)
                            ->setIsMain(true)
                    )
            )
            ->setCompany(
                (new CompanyModel())
                    ->setId(8348835)
            );

        $leadsCollection = new LeadsCollection();
        $leadsCollection->add($lead);

        try {
            $leadsCollection = $leadsService->add($leadsCollection);
        } catch (AmoCRMApiException $e) {
            printError($e);
            die;
        }
        if ($leadsCollection) {

            $tasksCollection = new TasksCollection();
            $task = new TaskModel();
            $task->setTaskTypeId(TaskModel::TASK_TYPE_ID_CALL)
                ->setText('Связаться с клиентом')
                ->setCompleteTill($end_of_day)
                ->setEntityType(EntityTypesInterface::LEADS)
                ->setEntityId($leadsCollection[0]->id)
                ->setResponsibleUserId($responsibleUserId);
            $tasksCollection->add($task);

            try {
                $tasksCollection = $tasksService->add($tasksCollection);

            } catch (AmoCRMApiException $e) {
                printError($e);
                die;
            }
        }

        return $this->redirect('/amocrm');
    }

    public function apiClient()
    {

        $clientId = $_ENV['CLIENT_ID'];
        $clientSecret = $_ENV['CLIENT_SECRET'];
        $redirectUri = $_ENV['CLIENT_REDIRECT_URI'];

        $apiClient = new AmoCRMApiClient($clientId, $clientSecret, $redirectUri);

        $accessToken = $this->getToken();

        return $apiClient->setAccessToken($accessToken)
            ->setAccountBaseDomain($accessToken->getValues()['baseDomain'])
            ->onAccessTokenRefresh(
                function (AccessTokenInterface $accessToken, string $baseDomain) {
                    saveToken(
                        [
                            'accessToken' => $accessToken->getToken(),
                            'refreshToken' => $accessToken->getRefreshToken(),
                            'expires' => $accessToken->getExpires(),
                            'baseDomain' => $baseDomain,
                        ]
                    );
                }
            );
    }

    public function getToken()
    {

        $accessToken = $this->session->get('token');

        if (
            isset($accessToken)
            && isset($accessToken['accessToken'])
            && isset($accessToken['refreshToken'])
            && isset($accessToken['expires'])
            && isset($accessToken['baseDomain'])
        ) {
            return new AccessToken([
                'access_token' => $accessToken['accessToken'],
                'refresh_token' => $accessToken['refreshToken'],
                'expires' => $accessToken['expires'],
                'baseDomain' => $accessToken['baseDomain'],
            ]);
        } else {
            exit('Invalid access token ' . var_export($accessToken, true));
        }
    }

    protected function getLeadsAll(AmoCRMApiClient $apiClient)
    {
        $leadsService = $apiClient->leads();
        $leads = $leadsService->get(null, [LeadModel::CONTACTS, LeadModel::CATALOG_ELEMENTS]);
        $leadsAll = [];

        foreach ($leads as $key => $value) {

            $lead = [
                "id" => $value->id,
                "name" => $value->name,
                //TODO переделать
                "manager" => $apiClient->users()->getOne($value->responsibleUserId)->name,
                "contacts" => array(),
            ];
            $getContacts = $value->getContacts();

            if ($getContacts) {
                foreach ($getContacts as $key => $value) {

                    $contact = $this->getContactById($apiClient, $value->id);

                    array_push($lead["contacts"], $contact);
                }
            }

            array_push($leadsAll, $lead);
        }

        return $leadsAll;
    }

    public function getContactById(AmoCRMApiClient $apiClient, $id)
    {
        $contact = array();

        $customFields = $apiClient->contacts()->getOne($id);
        $contact['id'] = $customFields->id;
        $contact['name'] = $customFields->name;

        foreach ($customFields->getCustomFieldsValues() as $key => $value) {
            foreach ($value->getValues() as $key => $valuecode) {
                if ($value->fieldCode == 'PHONE') {
                    $contact['fhone'] = $valuecode->value;
                } else {
                    $contact['email'] = $valuecode->value;
                }

            }

        }

        return $contact;

    }
}
