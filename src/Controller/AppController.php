<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class AppController extends AbstractController
{
    private $session;

    public function __construct(SessionInterface $session)
    {
        $state = bin2hex(random_bytes(16));
        $session->set('oauth2state', $state);
    }
    /**
     * @Route("/")
     */
    public function index()
    {

        return $this->render('home.html.twig');
    }

}
