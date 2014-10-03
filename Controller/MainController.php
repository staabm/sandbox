<?php

namespace PropelSandbox\Controller;

use FOS\RestBundle\Request\ParamFetcher;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Map\TableMap;
use PropelSandbox\Model\Fiddle;
use PropelSandbox\Model\FiddleQuery;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\Put;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\RequestParam;

class MainController extends Controller
{

    /**
     * @Route("/")
     * @Route("/{fiddle}",requirements={"fiddle" = "[a-zA-Z0-9]{7}"})
     * @Route("/example/{exampleId}",requirements={"exampleId" = "[a-zA-Z0-9]+"})
     * @Method("GET")
     */
    public function appAction(Request $request, $fiddle = '')
    {
        if ($fiddle) {
            $fiddle = FiddleQuery::create()->findPk($fiddle);
            if (!$fiddle) {
                throw $this->createNotFoundException('The fiddle does not exist.');
            }
        }

        return $this->render(
            'PropelSandboxBundle::app.twig.html'
        );
    }

    /**
     * @Route("/{fiddle}.json", requirements={"fiddle" = "[a-zA-Z0-9]{7}"})
     * @Method("GET")
     */
    public function fiddleAsJsonAction($fiddle, Request $request)
    {
        $fiddle = FiddleQuery::create()->findPk($fiddle);
        if (!$fiddle) {
            throw $this->createNotFoundException('The fiddle does not exist. [json]');
        }

        $result = $fiddle->exportModel();
        $result['editable'] = $this->isEditable($fiddle->getId(), $request);

        return $result;
    }

    /**
     * @Route("/my-fiddles")
     * @Method("GET")
     */
    public function myFiddles(Request $request)
    {
        $request->getSession()->start();
        $sessionId = $request->getSession()->getId();

        return FiddleQuery::create()
            ->select(['id', 'title', 'created_at'])
            ->filterBySessionOwner($sessionId)
            ->orderByCreatedAt(Criteria::DESC)
            ->find()
            ->toArray();
    }

    /**
     * @param string $fiddle
     * @param Request $request
     * @return bool
     */
    protected function isEditable($fiddle, Request $request)
    {
        if ($fiddle && $request->cookies->get('session')) {
            $request->getSession()->start();
            $sessionId = $request->getSession()->getId();

            $fiddle = FiddleQuery::create()->findPk($fiddle);
            if ($fiddle && $sessionId === $fiddle->getSessionOwner()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @QueryParam(name="fiddle", requirements="[a-zA-Z0-9]{7}", strict=true)
     *
     * @RequestParam(name="title", strict=false)
     * @RequestParam(name="schema", strict=false)
     * @RequestParam(name="php")
     *
     * @Post("/{fiddle}")
     */
    public function saveAndRunAction(Request $request, $fiddle, ParamFetcher $requestParam)
    {
        $request->getSession()->start();
        $sessionId = $request->getSession()->getId();
        $fiddleId = $fiddle;

        $fiddle = FiddleQuery::create()->findPk($fiddle);

        if (!$fiddle) {
            throw new \RuntimeException(sprintf('Fiddle %s not found.', $fiddleId));
        }

        if ($fiddle->getSessionOwner() !== $sessionId) {
            throw new \InvalidArgumentException('You are not allowed to do this.');
        }

        $title = $requestParam->get('title');
        $php = $requestParam->get('php');
        $schema = $requestParam->get('schema');

        $fiddle->setTitle($title);
        $fiddle->setSchema($schema);
        $fiddle->setPhp($php);

        $executor = $this->container->get('propelsandbox.executor');

        $executor->execute($fiddle);
        $fiddle->save();

        return $fiddle->exportModel();
    }

    /**
     * @Put("/")
     */
    public function newFiddleAction(Request $request)
    {
        $request->getSession()->start();
        $sessionId = $request->getSession()->getId();

        $id = null;
        $c = 0;
        while (true) {
            $c++;
            $id = substr(md5(microtime() * 10000 + mt_rand()), 0, 7);

            $fiddle = FiddleQuery::create()->select('Id')->findPk($id);

            if (!$fiddle) {
                $fiddle = new Fiddle();
                $fiddle->setId($id);
                $fiddle->setSessionOwner($sessionId);
                $fiddle->setCreatedAt(time());
                $fiddle->save();
                break;
            }

            if ($c > 1000) {
                throw new \RuntimeException('Could not create a new fiddle id for your. That should not happen :-(');
            }
        }

        return $id;
    }
}