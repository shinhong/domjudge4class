<?php declare(strict_types=1);

namespace DOMJudgeBundle\Controller\API;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use DOMJudgeBundle\Entity\Contest;
use DOMJudgeBundle\Service\DOMJudgeService;
use DOMJudgeBundle\Utils\Utils;
use FOS\RestBundle\Controller\FOSRestController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class AbstractRestController
 * @package DOMJudgeBundle\Controller\API
 */
abstract class AbstractRestController extends FOSRestController
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * AbstractRestController constructor.
     * @param EntityManagerInterface $entityManager
     * @param DOMJudgeService $DOMJudgeService
     */
    public function __construct(EntityManagerInterface $entityManager, DOMJudgeService $DOMJudgeService)
    {
        $this->entityManager   = $entityManager;
        $this->DOMJudgeService = $DOMJudgeService;
    }

    /**
     * Get all objects for this endpoint
     * @param Request $request
     * @return Response
     * @throws NonUniqueResultException
     */
    protected function performListAction(Request $request)
    {
        $queryBuilder = $this->getQueryBuilder($request);

        if ($request->query->has('ids')) {
            $ids = $request->query->get('ids', []);
            if (!is_array($ids)) {
                throw new BadRequestHttpException('\'ids\' should be an array of ID\'s to fetch');
            }

            $ids = array_unique($ids);

            $queryBuilder
                ->andWhere(sprintf('%s IN (:ids)', $this->getIdField()))
                ->setParameter(':ids', $ids);
        }

        $objects      = $queryBuilder
            ->getQuery()
            ->getResult();

        if (isset($ids) && count($objects) !== count($ids)) {
            throw new NotFoundHttpException('One or more objects not found');
        }

        return $this->renderData($request, $objects);
    }

    /**
     * Get a single object for this endpoint
     * @param Request $request
     * @param string $id
     * @return Response
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function performSingleAction(Request $request, string $id)
    {
        $queryBuilder = $this->getQueryBuilder($request)
            ->andWhere(sprintf('%s = :id', $this->getIdField()))
            ->setParameter(':id', $id);

        $object = $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();

        if ($object === null) {
            throw new NotFoundHttpException(sprintf('Object with ID \'%s\' not found', $id));
        }

        return $this->renderData($request, $object);
    }

    /**
     * Render the given data using the correct groups
     * @param Request $request
     * @param mixed $data
     * @return Response
     */
    protected function renderData(Request $request, $data): Response
    {
        $view = $this->view($data);

        // Set the user on the context, so it can be used to determine access to certain attributes
        $view->getContext()->setAttribute('user', $this->DOMJudgeService->getUser());

        $groups = ['Default'];
        if (!$request->query->has('strict')) {
            $groups[] = 'Nonstrict';
        }
        $view->getContext()->setGroups($groups);

        return $this->handleView($view);
    }

    /**
     * Get the query builder used for getting contests
     * @return QueryBuilder
     */
    protected function getContestQueryBuilder(): QueryBuilder
    {
        $now = Utils::now();
        $qb  = $this->entityManager->createQueryBuilder();
        $qb
            ->from('DOMJudgeBundle:Contest', 'c')
            ->select('c')
            ->andWhere('c.enabled = 1')
            ->andWhere($qb->expr()->orX(
                'c.deactivatetime is null',
                $qb->expr()->gt('c.deactivatetime', $now)
            ))
            ->orderBy('c.activatetime');

        // Filter on contests this user has access to
        if (!$this->DOMJudgeService->checkrole('jury')) {
            if ($this->DOMJudgeService->checkrole('team') && $this->DOMJudgeService->getUser()->getTeamid()) {
                $qb->join('c.teams', 'ct')
                    ->andWhere('ct.teamid = :teamid')
                    ->setParameter(':teamid', $this->DOMJudgeService->getUser()->getTeamid());
            } else {
                $qb->andWhere('c.public = 1');
            }
        }

        return $qb;
    }

    /**
     * @param Request $request
     * @return int
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function getContestId(Request $request): int
    {
        if (!$request->attributes->has('cid')) {
            throw new BadRequestHttpException('cid parameter missing');
        }

        $qb = $this->getContestQueryBuilder();
        $qb
            ->andWhere('c.cid = :cid')
            ->setParameter(':cid', $request->attributes->get('cid'));

        /** @var Contest $contest */
        $contest = $qb->getQuery()->getOneOrNullResult();

        if ($contest === null) {
            throw new NotFoundHttpException(sprintf('Contest with ID \'%s\' not found', $request->attributes->get('cid')));
        }

        return $contest->getCid();
    }

    /**
     * Get the query builder to use for request for this REST endpoint
     * @param Request $request
     * @return QueryBuilder
     * @throws NonUniqueResultException
     */
    abstract protected function getQueryBuilder(Request $request): QueryBuilder;

    /**
     * Return the field used as ID in requests
     * @return string
     */
    abstract protected function getIdField(): string;
}
