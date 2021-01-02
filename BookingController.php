<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $response = null;

        $userTypes = [
            env('ADMIN_ROLE_ID'),
            env('SUPERADMIN_ROLE_ID')
        ];

        if ($request->get('user_id')) {

            $response = $this->repository->getUsersJobs($request->get('user_id'));
        } elseif (in_array($request->__authenticatedUser->user_type, $userTypes)) {

            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $response = $this->repository->store($request->__authenticatedUser, $request->all());

        return response($response);
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $response = $this->repository->updateJob(
            $id,
            $request->except(['_token', 'submit']),
            $request->__authenticatedUser
        );

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $response = $this->repository->storeJobEmail($request->all());

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $response = null;

        if ($request->get('user_id')) {

            $response = $this->repository->getUsersJobsHistory($request->get('user_id'), $request);
        }

        return $response;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $response = $this->repository->acceptJob(
            $request->all(),
            $request->__authenticatedUser
        );

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $response = $this->repository->acceptJobWithId(
            $request->get('job_id'),
            $request->__authenticatedUser
        );

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $response = $this->repository->cancelJobAjax($request->all(), $request->__authenticatedUser);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $response = $this->repository->endJob($request->all());

        return response($response);
    }

    public function customerNotCall(Request $request)
    {
        $response = $this->repository->customerNotCall($request->all());

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->repository->getPotentialJobs($request->__authenticatedUser);

        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        if ($request->flagged) {
            if (empty($request->admincomment))
                return "Please, add comment";
        }

        Distance::where('job_id', '=', $request->jobid)->update([
            'distance' => $request->distance ?? '',
            'time' => $request->time ?? ''
        ]);

        Job::where('id', '=', $request->jobid)->update([
            'admin_comments' => $request->admincomment ?? '',
            'flagged' => ($request->flagged == 'true') ? "yes" : "no",
            'session_time' => $request->session ?? '',
            'manually_handled' => ($request->manually_handled == 'true') ? 'yes' : 'no',
            'by_admin' => ($request->by_admin == 'true') ? 'yes' : 'no',
        ]);

        return response('Record updated!');
    }

    public function reopen(Request $request)
    {
        $response = $this->repository->reopen($request->all());

        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $job = $this->repository->find($request->jobid);

        $jobData = $this->repository->jobToData($job);

        $this->repository->sendNotificationTranslator($job, $jobData, '*');

        return response([
            'success' => 'Push sent'
        ]);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        try {
            $job = $this->repository->find($request->jobid);

            $this->repository->jobToData($job);

            $this->repository->sendSMSNotificationToTranslator($job);

            return response([
                'success' => 'SMS sent'
            ]);
        } catch (\Exception $e) {

            return response([
                'success' => $e->getMessage()
            ]);
        }
    }
}
