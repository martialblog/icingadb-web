<?php

namespace Icinga\Module\Icingadb\Controllers;

use Icinga\Exception\NotFoundError;
use Icinga\Module\Icingadb\Common\CommandActions;
use Icinga\Module\Icingadb\Common\Links;
use Icinga\Module\Icingadb\Common\ServiceLinks;
use Icinga\Module\Icingadb\Model\History;
use Icinga\Module\Icingadb\Model\Service;
use Icinga\Module\Icingadb\Web\Controller;
use Icinga\Module\Icingadb\Widget\Detail\ObjectDetail;
use Icinga\Module\Icingadb\Widget\Detail\QuickActions;
use Icinga\Module\Icingadb\Widget\DowntimeList;
use Icinga\Module\Icingadb\Widget\ItemList\CommentList;
use Icinga\Module\Icingadb\Widget\ItemList\HistoryList;
use Icinga\Module\Icingadb\Widget\ServiceList;
use Icinga\Module\Icingadb\Widget\ShowMore;
use ipl\Sql\Sql;
use ipl\Web\Url;

class ServiceController extends Controller
{
    use CommandActions;

    /** @var Service The service object */
    protected $service;

    public function init()
    {
        $name = $this->params->shiftRequired('name');
        $hostName = $this->params->shiftRequired('host.name');

        $query = Service::on($this->getDb())->with([
            'state',
            'host',
            'host.state'
        ]);
        $query->getSelectBase()
            ->where(['service.name = ?' => $name])
            ->where(['service_host.name = ?' => $hostName]);

        $this->applyMonitoringRestriction($query);

        /** @var Service $service */
        $service = $query->first();
        if ($service === null) {
            throw new NotFoundError($this->translate('Service not found'));
        }

        $this->service = $service;

        $this->setTitleTab($this->getRequest()->getActionName());
    }

    public function indexAction()
    {
        if ($this->service->state->is_overdue) {
            $this->controls->addAttributes(['class' => 'overdue']);
        }
        $this->addControl((new ServiceList([$this->service]))->setViewMode('minimal'));
        $this->addControl(new QuickActions($this->service));

        $this->addContent(new ObjectDetail($this->service));

        $this->setAutorefreshInterval(10);
    }

    public function commentsAction()
    {
        $this->setTitle($this->translate('Comments'));

        $this->addControl((new ServiceList([$this->service]))->setViewMode('minimal'));

        $comments = $this->service->comment;

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($comments);

        yield $this->export($comments);

        $this->addControl($paginationControl);
        $this->addControl($limitControl);

        $this->addContent(new CommentList($comments));

        $this->setAutorefreshInterval(10);
    }

    public function downtimesAction()
    {
        $this->setTitle($this->translate('Downtimes'));

        $this->addControl((new ServiceList([$this->service]))->setViewMode('minimal'));

        $downtimes = $this->service->downtime;

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($downtimes);

        yield $this->export($downtimes);

        $this->addControl($paginationControl);
        $this->addControl($limitControl);

        $this->addContent(new DowntimeList($downtimes));

        $this->setAutorefreshInterval(10);
    }

    public function historyAction()
    {
        $compact = $this->params->shift('view') === 'compact'; // TODO: Don't shift here..

        if ($this->service->state->is_overdue) {
            $this->controls->addAttributes(['class' => 'overdue']);
        }

        $this->addControl((new ServiceList([$this->service]))->setViewMode('minimal'));

        $db = $this->getDb();

        $history = History::on($db)->with([
            'host',
            'host.service',
            'host.state',
            'service',
            'service.state',
            'service.host',
            'service.host.state',
            'comment',
            'downtime',
            'notification',
            'state'
        ]);

        $history
            ->getSelectBase()
            ->where([
                'history_host_service.id = ?' => $this->service->id,
                'history_service.id = ?' => $this->service->id
            ], Sql::ANY);

        $url = Url::fromPath('icingadb/history')->setParams($this->params);
        if (! $this->params->has('page') || ($page = (int) $this->params->shift('page')) < 1) {
            $page = 1;
        }

        $limitControl = $this->createLimitControl();

        $history->limit($limitControl->getLimit());
        if ($page > 1) {
            if ($compact) {
                $history->offset(($page - 1) * $limitControl->getLimit());
            } else {
                $history->limit($page * $limitControl->getLimit());
            }
        }

        yield $this->export($history);

        $showMore = (new ShowMore(
            $history->peekAhead()->execute(),
            (clone $url)->setParam('page', $page + 1)
                ->setAnchor('page-' . ($page + 1))
        ))
            ->setLabel('Load More')
            ->setAttribute('data-no-icinga-ajax', true);

        $this->addControl($limitControl);

        $historyList = (new HistoryList($history))
            ->setPageSize($limitControl->getLimit());
        if ($compact) {
            $historyList->setPageNumber($page);
        }

        // TODO: Dirty, really dirty, find a better solution (And I don't just mean `getContent()` !)
        $historyList->add($showMore->setTag('li')->addAttributes(['class' => 'list-item']));
        if ($compact && $page > 1) {
            $this->document->add($historyList->getContent());
        } else {
            $this->addContent($historyList);
        }
    }

    protected function createTabs()
    {
        return $this
            ->getTabs()
            ->add('index', [
                'label'  => $this->translate('Service'),
                'url'    => Links::service($this->service, $this->service->host)
            ])
            ->add('history', [
                'label'  => $this->translate('History'),
                'url'    => ServiceLinks::history($this->service, $this->service->host)
            ]);
    }

    protected function setTitleTab($name)
    {
        $tab = $this->createTabs()->get($name);

        if ($tab !== null) {
            $tab->setActive();

            $this->view->title = $tab->getLabel();
        }
    }

    public function fetchCommandTargets()
    {
        return [$this->service];
    }

    public function getCommandTargetsUrl()
    {
        return Links::service($this->service, $this->service->host);
    }
}
