<?php

namespace DTApi\Repository;

use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use DTApi\Mailers\AppMailer;
use DTApi\Models\Translator;
use Illuminate\Http\Request;
use DTApi\Events\SessionEnded;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCanceled;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . Carbon::now()->format("Y-m-d") . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobs($userId)
    {
        $userType = '';
        $emergencyJobs = [];
        $noramlJobs = [];
        $cuser = User::findOrFail($userId);

        if ($cuser->is('customer')) {
            $userType = 'customer';
            $jobs = $cuser->jobs()
                ->with(
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback'
                )->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        } elseif ($cuser->is('translator')) {
            $userType = 'translator';
            $jobs = Job::getTranslatorJobs($cuser->id, 'new')
                ->pluck('jobs')
                ->all();
        }
        if ($jobs) {
            foreach ($jobs as $jobItem) {
                if ($jobItem->immediate == 'yes')
                    $emergencyJobs[] = $jobItem;
                else
                    $noramlJobs[] = $jobItem;
            }
            $noramlJobs = collect($noramlJobs)
                ->each(function ($item) use ($userId) {
                    $item['usercheck'] = Job::checkParticularJob($userId, $item);
                })->sortBy('due')
                ->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => $noramlJobs,
            'cuser' => $cuser,
            'userType' => $userType
        ];
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobsHistory($userId, Request $request)
    {
        $response = null;
        $usertype = '';
        $emergencyJobs = [];
        $pageNum = $request->page ?? "1";
        $cuser = User::findOrFail($userId);

        if ($cuser->is('customer')) {

            $usertype = 'customer';
            $jobs = $cuser->jobs()
                ->with(
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback',
                    'distance'
                )->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
            $response = [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0
            ];
        } elseif ($cuser->is('translator')) {

            $usertype = 'translator';
            $jobsIds = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pageNum);
            $totaljobs = $jobsIds->total();
            $numPages = ceil($totaljobs / 15);
            $response = [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs' => $jobsIds,
                'jobs' => $jobsIds,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => $numPages,
                'pagenum' => $pageNum
            ];
        }
        return $response;
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5;
        $cuser = $user;
        $consumerType = $user->userMeta->consumer_type;
        $consumerTypes = [
            "rwsconsumer" => "rws",
            "ngo" => "unpaid",
            "paid" => "paid"
        ];
        $response = [
            "status" => "fail",
            "message" => __("Du måste fylla in alla fält"),
        ];

        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            $response['message'] = __("Translator can not create booking");
            return $response;
        }

        if (!isset($data['from_language_id'])) {
            $response['field_name'] = "from_language_id";
            return $response;
        }
        if (isset($data['immediate']) && $data['immediate'] == 'no') {
            if (empty($data['due_date'] ?? "")) {
                $response['field_name'] = "due_date";
                return $response;
            }
            if (empty($data['due_time'] ?? "")) {
                $response['field_name'] = "due_time";
                return $response;
            }
            if (empty($data['customer_phone_type'] ?? "")) {
                $response['field_name'] = "customer_phone_type";
                return $response;
            }
        }
        if (empty($data['duration'] ?? "")) {
            $response['field_name'] = "duration";
            return $response;
        }

        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinute($immediatetime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            if ($due_carbon->isPast()) {
                $response['status'] = 'fail';
                $response['message'] = __("Can't create booking in past");
                return $response;
            }
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? "yes" : "no";
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? "yes" : "no";
        $response['customer_physical_type'] = isset($data['customer_physical_type']) ? "yes" : "no";
        $data['job_type'] = array_key_exists($consumerType, $consumerTypes) ? $consumerTypes[$consumerType] : "";

        if (in_array('male', $data['job_for']))
            $data['gender'] = 'male';
        else if (in_array('female', $data['job_for']))
            $data['gender'] = 'female';

        if (in_array('normal', $data['job_for']))
            $data['certified'] = 'normal';
        else if (in_array('certified', $data['job_for']))
            $data['certified'] = 'yes';
        else if (in_array('certified_in_law', $data['job_for']))
            $data['certified'] = 'law';
        else if (in_array('certified_in_helth', $data['job_for']))
            $data['certified'] = 'health';
        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for']))
            $data['certified'] = 'both';
        else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
            $data['certified'] = 'n_law';
        else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']))
            $data['certified'] = 'n_health';


        $data['b_created_at'] = Carbon::now()->format("Y-m-d H:i:s");
        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        if (isset($due))
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);

        $job = $cuser->jobs()->create($data);

        unset($response['message']);
        $response['status'] = 'success';
        $response['id'] = $job->id;

        return $response;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $response = [];
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $userType = $data['user_type'];
        $job->user_email = $data['user_email'] ?? "";
        $job->reference = $data['reference'] ?? "";
        $user = $job->user()->get()->first();

        if (isset($data['address'])) {

            $job->address = (!empty($data['address'])) ? $data['address'] : $user->userMeta->address;
            $job->instructions = (!empty($data['instructions'])) ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = (!empty($data['town'])) ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = __('Vi har mottagit er tolkbokning. Bokningsnr: # :jobId', ["jobId" => $job->id]);
        $sendData = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        $response['type'] = $userType;
        $response['job'] = $job;
        $response['status'] = 'success';

        return $response;
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = [];            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0] ?? "";
        $due_time = $due_Date[1] ?? "";

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];
        if (!is_null($job->gender)) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if (!is_null($job->certified)) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * @param array $postData
     */
    public function jobEnd($postData = [])
    {
        $completedDate = Carbon::now()->format("Y-m-d H:i:s");
        $jobId = $postData["job_id"] ?? "";
        $job_detail = Job::with('translatorJobRel')->find($jobId);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = Carbon::now()->format("Y-m-d H:i:s");
        $job->status = 'completed';
        $job->session_time = $interval;
        // email
        $user = $job->user()->get()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = __('Information om avslutad tolkning för bokningsnummer # :jobId', ["jobId" => $job->id]);
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        Event::fire(new SessionEnded($job, ($postData['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));
        // email
        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $postData['userid'] ?? "";
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId($userId)
    {
        $userMeta = UserMeta::where('user_id', $userId)->first();
        $translator_type = $userMeta->translator_type;
        $jobType = 'unpaid';
        if ($translator_type == 'professional')
            $jobType = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $jobType = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $userId)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;
        $jobIds = Job::getJobs($userId, $jobType, 'pending', $userlanguage, $gender, $translatorLevel);

        foreach ($jobIds as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $userId);
            if (
                ($job->customer_phone_type == 'no' || $job->customer_phone_type == '')
                && $job->customer_physical_type == 'yes'
                && $checktown == false
            ) {
                unset($jobIds[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($jobIds);

        return $jobs;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translatorArray = [];            // suitable translators (no need to delay push)
        $delpayTranslatorArray = [];     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if (
                $oneUser->user_type == '2'
                && $oneUser->status == '1'
                && $oneUser->id != $exclude_user_id
            ) {
                // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id))
                    continue;
                $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes')
                    continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($jobForTranslator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpayTranslatorArray[] = $oneUser;
                                } else {
                                    $translatorArray[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msgContents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . (($data['immediate'] == 'no') ? $data['due'] : "");
        $msgText = array(
            "en" => $msgContents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . Carbon::now()->format("Y-m-d") . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo(__('Push send for job :jobId', ["jobId" => $job->id]), [$translatorArray, $delpayTranslatorArray, $msgText, $data]);
        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpayTranslatorArray, $job->id, $data, $msgText, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();
        // prepare message templates
        $date = Carbon::createFromFormat('d.m.Y', $job->due);
        $time = Carbon::createFromFormat('H:i', $job->due);
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param $userId
     * @return bool
     */
    public function isNeedToDelayPush($userId)
    {
        if (!DateTimeHelper::isNightTime())
            return false;
        $notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        if ($notGetNightTime == 'yes')
            return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $userId
     * @return bool
     */
    public function isNeedToSendPush($userId)
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        if ($notGetNotification == 'yes')
            return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $jobId
     * @param $data
     * @param $msgText
     * @param $isNeedDelay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobId, $data, $msgText, $isNeedDelay)
    {

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . Carbon::now()->format("Y-m-d") . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);

        $onesignalAppID = (env('APP_ENV') == 'prod') ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", (env('APP_ENV') == 'prod') ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $userTags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            $androidSound = ($data['immediate'] == 'no') ? 'normal_booking' : 'emergency_booking';
            $iosSound = ($data['immediate'] == 'no') ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($userTags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msgText,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $androidSound,
            'ios_sound'      => $iosSound
        );
        if ($isNeedDelay) {
            $nextBusinessTime = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $nextBusinessTime;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo(__('Push send for job :jobId curl answer', ["jobId" => $jobId]), [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $jobType = $job->job_type;

        if ($jobType == 'paid')
            $translator_type = 'professional';
        else if ($jobType == 'rws')
            $translator_type = 'rwstranslator';
        else if ($jobType == 'unpaid')
            $translator_type = 'volunteer';

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevel = [];

        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translatorLevel[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translatorLevel[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translatorLevel[] = 'Certified';
                $translatorLevel[] = 'Certified with specialisation in law';
                $translatorLevel[] = 'Certified with specialisation in health care';
                $translatorLevel[] = 'Layman';
                $translatorLevel[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translatorLevel, $translatorsId);

        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $currentTranslator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($currentTranslator))
            $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $logData = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) $logData[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $logData[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];
        $logText = __(
            'USER #:userId(:name)' . ' has been updated booking <a class="openjob" href="/admin/jobs/:id"># :id</a> with data:  ',
            [
                "userId" => $cuser->id,
                "name" => $cuser->name,
                "id" => $id,
                "id" => $id,
            ]
        );
        $this->logger->addInfo($logText, $logData);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $logData = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return [
                    'statusChanged' => $statusChanged,
                    'log_data' => $logData
                ];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {

        $job->status = $data['status'];
        $user = $job->user()->first();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = Carbon::now()->format("Y-m-d H:i:s");
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = __(
                'Vi har nu återöppnat er bokning av :language tolk för bokning #:id',
                [
                    "language" => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                    "id" => $job->id
                ]
            );
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {

        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if (empty($data['admin_comments']))
                return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        if ($data['admin_comments'] == '')
            return false;
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = Carbon::now()->format("Y-m-d H:i:s");
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => __('faktura')
            ];

            $subject = __(
                'Information om avslutad tolkning för bokningsnummer # :id',
                [
                    "id" => $job->id
                ]
            );
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = __(
                'Information om avslutad tolkning för bokningsnummer # :id',
                [
                    "id" => $job->id
                ]
            );
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {

        if (empty($data['admin_comments']) && $data['status'] == 'timedout')
            return false;
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $this->jobToData($job);
            $subject = __(
                'Bekräftelse - tolk har accepterat er bokning (bokning # :id)',
                [
                    "id" => $job->id
                ]
            );
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = __('Avbokning av bokningsnr: # :id', ["id" => $job->id]);
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . Carbon::now()->format("Y-m-d") . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msgText = [
                "en" => __(
                    'Detta är en påminnelse om att du har en :language tolkning (på plats i :town) kl :due_1 på :due_0 som vara i :duration min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!',
                    [
                        "langauge" => $language,
                        "due_0" => $due_explode[0],
                        "due_1" => $due_explode[1],
                        "town" => $job->town,
                        "duration" => $duration
                    ]
                )
            ];
        else
            $msgText = [
                "en" => __(
                    'Detta är en påminnelse om att du har en :language tolkning (telefon) kl :due_1 på :due_0 som vara i :duration min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!',
                    [
                        "langauge" => $language,
                        "due_0" => $due_explode[0],
                        "due_1" => $due_explode[1],
                        "town" => $job->town,
                        "duration" => $duration
                    ]
                )
            ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            if (empty($data['admin_comments']))
                return false;
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if (
                empty($data['admin_comments'])
                && $data['status'] == 'timedout'
            )
                return false;

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {

                $user = $job->user()->first();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = __('Information om avslutad tolkning för bokningsnummer #:id', ["id" => $job->id]);
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = __('Information om avslutad tolkning för bokningsnummer #:id', ["id" => $job->id]);
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $currentTranslator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($currentTranslator, $data, $job)
    {
        $translatorChanged = false;

        if (
            !is_null($currentTranslator)
            || (isset($data['translator']) && !empty($data['translator']))
            || !empty($data['translator_email'])
        ) {
            $logData = [];
            if (
                !is_null($currentTranslator)
                && ((isset($data['translator']) && ($currentTranslator->user_id != $data['translator'])
                    || !empty($data['translator_email'])))
                && (isset($data['translator'])
                    && !empty($data['translator']))
            ) {
                if (!empty($data['translator_email']))
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = $currentTranslator->toArray();
                $newTranslator['user_id'] = $data['translator'];
                unset($newTranslator['id']);
                $newTranslator = Translator::create($newTranslator);
                $currentTranslator->cancel_at = Carbon::now();
                $currentTranslator->save();
                $logData[] = [
                    'old_translator' => $currentTranslator->user->email,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            } elseif (
                is_null($currentTranslator)
                && isset($data['translator'])
                && ($data['translator'] != 0
                    || !empty($data['translator_email']))
            ) {
                if (!empty($data['translator_email']))
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $logData[] = [
                    'old_translator' => null,
                    'new_translator' => $newTranslator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return [
                    'translatorChanged' => $translatorChanged,
                    'new_translator' => $newTranslator,
                    'log_data' => $logData
                ];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $logData = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $logData];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * @param $job
     * @param $currentTranslator
     * @param $newTranslator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $user->user_email : $user->email;
        $name = $user->name;
        $subject = __('Meddelande om tilldelning av tolkuppdrag för uppdrag # :id', ["id" => $job->id]);
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($currentTranslator) {
            $user = $currentTranslator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $newTranslator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $user->user_email : $user->email;
        $name = $user->name;
        $subject =
            __('Meddelande om ändring av tolkbokning för uppdrag # :id', ["id" => $job->id]);
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $user->user_email : $user->email;
        $name = $user->name;
        $subject = __('Meddelande om ändring av tolkbokning för uppdrag # :id', ["id" => $job->id]);
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = [
            "en" => __(
                'Tyvärr har ingen tolk accepterat er bokning: (:language, :duration min, :due). Vänligen pröva boka om tiden.',
                [
                    "language" => $language,
                    "duration" => $job->duration,
                    "due" => $job->due,
                ]
            )
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msgText, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $jobId
     */
    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = Job::findOrFail($jobId);
        $userMeta = $job->user->userMeta()->first();
        $data = [];            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $userMeta->city;
        $data['customer_type'] = $userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0] ?? "";
        $due_time = $due_Date[1] ?? "";
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;
        $data['job_for'] = [];
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msgText = [
                "en" => 'Du har nu fått platstolkningen för :language kl :duration den :due Vänligen säkerställ att du är förberedd för den tiden. Tack!',
                [
                    "language" => $language,
                    "duration" => $job->duration,
                    "due" => $job->due,
                ]
            ];
        else
            $msgText = [
                "en" => __('Du har nu fått telefontolkningen för :language kl :duration den :due Vänligen säkerställ att du är förberedd för den tiden. Tack!'),
                [
                    "language" => $language,
                    "duration" => $job->duration,
                    "due" => $job->due,
                ]
            ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msgText, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $userTags .= ',{"operator": "OR"},';
            }
            $userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $userTags .= ']';
        return $userTags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $cuser = $user;
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        if (!Job::isTranslatorAlreadyBooked($jobId, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $jobId)) {

                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = [];
            $response['list'] = json_encode([
                'jobs' => $jobs,
                'job' => $job
            ], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = __('Du har redan en bokning den tiden! Bokningen är inte accepterad.');
        }

        return $response;
    }


    /**
     * Function to accept the job with the job id
     * @param  int $jobId 
     * * @param  object $cuser   
     * @return array         
     */
    public function acceptJobWithId($jobId, $cuser)
    {
        $job = Job::findOrFail($jobId);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($jobId, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $subject = __('Bekräftelse - tolk har accepterat er bokning (bokning # :id)', ["id" => $job->id]);
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = [];
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => __('Din bokning för :language translators, :duration min, :due har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.', [
                        "language" => $language,
                        "duration" => $job->duration,
                        "due" => $job->due,
                    ])
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msgText, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = __('Du har nu accepterat och fått bokningen för :language tolk :duration min :due', [
                    "language" => $language,
                    "duration" => $job->duration,
                    "due" => $job->due,
                ]);
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = __('Denna :language tolkning :duration min :due har redan accepterats av annan tolk. Du har inte fått denna tolkning', [
                    "language" => $language,
                    "duration" => $job->duration,
                    "due" => $job->due,
                ]);
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = __('Du har redan en bokning den tiden :due. Du har inte fått denna tolkning', ['due' => $job->due]);
        }
        return $response;
    }
    /**
     * @param  object $data 
     * * @param  object $user   
     * @return array         
     */
    public function cancelJobAjax($data, $user)
    {
        $response = [];
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $cuser = $user;
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = ($job->withdraw_at->diffInHours($job->due) >= 24)
                ? 'withdrawbefore24'
                : 'withdrawafter24';
            $response['jobstatus'] = 'success';
            $job->save();
            Event::fire(new JobWasCanceled($job));
            if ($translator) {
                $data = [];
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = array(
                    "en" => __(
                        'Kunden har avbokat bokningen för :due tolk, :duration min, :due. Var god och kolla dina tidigare bokningar för detaljer.',
                        [
                            "language" => $language,
                            "duration" => $job->duration,
                            "due" => $job->due
                        ]
                    )
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msgText, $this->isNeedToDelayPush($translator->id)); // send Session Cancel Push to Translaotor
                }
            }
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = [];
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msgText = array(
                        "en" => __(
                            'Er :language tolk, :duration min :due, har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.',
                            [
                                "language" => $language,
                                "duration" => $job->duration,
                                "due" => $job->due
                            ]
                        )
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $jobId, $data, $msgText, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->createdAt = Carbon::now()->format("Y-m-d H:i:s");
                $job->willExpireAt = TeHelper::willExpireAt($job->due, Carbon::now()->format("Y-m-d H:i:s"));
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $jobId);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = __('Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!');
            }
        }
        return $response;
    }

    /**
     * Function to get the potential jobs for paid,rws,unpaid translators
     * @param  object $cuser   
     * @return array         
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $jobType = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $jobType = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $jobType = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translatorLevel = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userlanguage, $gender, $translatorLevel);
        foreach ($jobIds as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                    unset($jobIds[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($jobIds[$k]);
            }
        }
        return $jobIds;
    }
    /**
     * @param  object $postData   
     * @return array         
     */
    public function endJob($postData)
    {
        $completedDate = Carbon::now()->format("Y-m-d H:i:s");
        $jobId = $postData["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobId);

        if ($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = Carbon::now()->format("Y-m-d H:i:s");
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $postData['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }
    /**
     * @param  object $postData   
     * @return array         
     */
    public function customerNotCall($postData)
    {
        $completedDate = Carbon::now()->format("Y-m-d H:i:s");
        $jobId = $postData["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobId);
        $job = $job_detail;
        $job->end_at = Carbon::now()->format("Y-m-d H:i:s");
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completedDate;
        $tr->completed_by = $tr->user_id;

        $job->save();
        $tr->save();

        return [
            "status" => "success"
        ];
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;
        $query = Job::query()
            ->with(
                'user',
                'language',
                'feedback.user',
                'translatorJobRel.user',
                'distance'
            );

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID'))

            $allJobs = $this->getSuperAdminJobs($query, $requestData, $limit);

        else

            $allJobs = $this->getUserJobs($query, $requestData, $limit, $consumerType);

        return $allJobs;
    }
    /**
     * @return array         
     */
    private function getSuperAdminJobs($query, $requestData, $limit)
    {
        $allJobs = $query->when($requestData['feedback'] != 'false', function ($queryInner) use ($requestData) {
            $queryInner->where('ignore_feedback', '0');
            $queryInner->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestData['count']) && $requestData['count'] != 'false')
                return [
                    'count' => $queryInner->count()
                ];
        })
            ->when($requestData['id'], function ($queryInner) use ($requestData) {
                if (is_array($requestData['id']))
                    $queryInner->whereIn('id', $requestData['id']);
                else
                    $queryInner->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            })
            ->when($requestData['lang'], function ($queryInner) use ($requestData) {
                $queryInner->whereIn('from_language_id', $requestData['lang']);
            })
            ->when($requestData['status'], function ($queryInner) use ($requestData) {
                $queryInner->whereIn('status', $requestData['status']);
            })
            ->when($requestData['expired_at'], function ($queryInner) use ($requestData) {
                $queryInner->where('expired_at', '>=', $requestData['expired_at']);
            })
            ->when($requestData['will_expire_at'], function ($queryInner) use ($requestData) {
                $queryInner->where('will_expire_at', '>=', $requestData['will_expire_at']);
            })
            ->when($requestData['customer_email'] && count($requestData['customer_email']), function ($queryInner) use ($requestData) {
                $users = DB::table('users')
                    ->whereIn('email', $requestData['customer_email'])
                    ->get();
                if ($users) {
                    $queryInner->whereIn('user_id', collect($users)->pluck('id')
                        ->all());
                }
            })
            ->when($requestData['translator_email'] && count($requestData['translator_email']), function ($queryInner) use ($requestData) {
                $users = DB::table('users')
                    ->whereIn('email', $requestData['translator_email'])
                    ->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')
                        ->whereNull('cancel_at')
                        ->whereIn('user_id', collect($users)->pluck('id')->all())
                        ->lists('job_id');
                    $queryInner->whereIn('id', $allJobIDs);
                }
            })
            ->when($requestData['filter_timetype'] == "created", function ($queryInner) use ($requestData) {
                if (!empty($requestData['from'] ?? "")) {
                    $queryInner->where('created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'] ?? "")) {
                    $to = $requestData["to"] . " 23:59:00";
                    $queryInner->where('created_at', '<=', $to);
                }
                $queryInner->orderBy('created_at', 'desc');
            })
            ->when($requestData['filter_timetype'] == "due", function ($queryInner) use ($requestData) {
                if (!empty($requestData['from'] ?? "")) {
                    $queryInner->where('created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'] ?? "")) {
                    $to = $requestData["to"] . " 23:59:00";
                    $queryInner->where('created_at', '<=', $to);
                }
                $queryInner->orderBy('created_at', 'desc');
            })
            ->when($requestData['job_type'], function ($queryInner) use ($requestData) {
                $queryInner->whereIn('job_type', $requestData['job_type']);
            })
            ->when($requestData['physical'], function ($queryInner) use ($requestData) {
                $queryInner->where('customer_physical_type', $requestData['physical']);
                $queryInner->where('ignore_physical', 0);
            })
            ->when($requestData['phone'], function ($queryInner) use ($requestData) {
                $queryInner->where('customer_phone_type', $requestData['phone']);
                if (isset($requestData['physical']))
                    $queryInner->where('ignore_physical_phone', 0);
            })
            ->when($requestData['flagged'], function ($queryInner) use ($requestData) {
                $queryInner->where('flagged', $requestData['flagged']);
                $queryInner->where('ignore_flagged', 0);
            })
            ->when($requestData['distance'] == "empty", function ($queryInner) {
                $queryInner->whereDoesntHave('distance');
            })
            ->when($requestData['salary'] == "yes", function ($queryInner) {
                $queryInner->whereDoesntHave('user.salaries');
            })
            ->when($requestData['count'] == "true", function ($queryInner) {
                $allJobs = $queryInner->count();

                return [
                    'count' => $allJobs
                ];
            })
            ->when($requestData['consumer_type'], function ($queryInner) use ($requestData) {
                $queryInner->whereHas('user.userMeta', function ($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            })
            ->when($requestData['booking_type'], function ($queryInner) use ($requestData) {
                if ($requestData['booking_type'] == 'physical')
                    $queryInner->where('customer_physical_type', 'yes');
                if ($requestData['booking_type'] == 'phone')
                    $queryInner->where('customer_phone_type', 'yes');
            });
        if ($limit == 'all')
            $allJobs = $allJobs->get();
        else
            $allJobs = $allJobs->paginate(15);
        return $allJobs;
    }
    /**
     * @return array         
     */
    private function getUserJobs($query, $requestData, $limit, $consumerType)
    {
        $allJobs = $query->when($requestData['feedback'] != 'false', function ($queryInner) use ($requestData) {
            $queryInner->where('ignore_feedback', '0');
            $queryInner->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestData['count']) && $requestData['count'] != 'false')
                return [
                    'count' => $queryInner->count()
                ];
        })
            ->when($requestData['id'], function ($queryInner) use ($requestData) {

                $queryInner->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            })
            ->when($requestData['lang'], function ($queryInner) use ($requestData) {
                $queryInner->whereIn('from_language_id', $requestData['lang']);
            })
            ->when($requestData['status'], function ($queryInner) use ($requestData) {
                $queryInner->whereIn('status', $requestData['status']);
            })
            ->when($requestData['expired_at'], function ($queryInner) use ($requestData) {
                $queryInner->where('expired_at', '>=', $requestData['expired_at']);
            })
            ->when($requestData['will_expire_at'], function ($queryInner) use ($requestData) {
                $queryInner->where('will_expire_at', '>=', $requestData['will_expire_at']);
            })
            ->when($requestData['customer_email'] && count($requestData['customer_email']), function ($queryInner) use ($requestData) {
                $user = DB::table('users')
                    ->where('email', $requestData['customer_email'])
                    ->first();
                if ($user) {
                    $queryInner->where('user_id', '=', $user->id);
                }
            })
            ->when($requestData['translator_email'] && count($requestData['translator_email']), function ($queryInner) use ($requestData) {
                $users = DB::table('users')
                    ->whereIn('email', $requestData['translator_email'])
                    ->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')
                        ->whereNull('cancel_at')
                        ->whereIn('user_id', collect($users)->pluck('id')->all())
                        ->lists('job_id');
                    $queryInner->whereIn('id', $allJobIDs);
                }
            })
            ->when($requestData['filter_timetype'] == "created", function ($queryInner) use ($requestData) {
                if (!empty($requestData['from'] ?? "")) {
                    $queryInner->where('created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'] ?? "")) {
                    $to = $requestData["to"] . " 23:59:00";
                    $queryInner->where('created_at', '<=', $to);
                }
                $queryInner->orderBy('created_at', 'desc');
            })
            ->when($requestData['filter_timetype'] == "due", function ($queryInner) use ($requestData) {
                if (!empty($requestData['from'] ?? "")) {
                    $queryInner->where('created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'] ?? "")) {
                    $to = $requestData["to"] . " 23:59:00";
                    $queryInner->where('created_at', '<=', $to);
                }
                $queryInner->orderBy('created_at', 'desc');
            })
            ->when($consumerType == 'RWS', function ($queryInner) use ($requestData) {
                $queryInner->whereIn('job_type', 'rws');
            })
            ->when($consumerType == 'unpaid', function ($queryInner) use ($requestData) {
                $queryInner->whereIn('job_type', 'rws');
            });

        if ($limit == 'all')
            $allJobs = $allJobs->get();
        else
            $allJobs = $allJobs->paginate(15);
        return $allJobs;
    }
    /**
     * @return array         
     */
    public function alerts()
    {
        $jobs = Job::all();
        $allJobs = null;
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs[$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');
        $cuser = Auth::user();

        if ($cuser && $cuser->is('superadmin')) {
            $query = jobs::query()
                ->with(['languages' => function ($query) {
                    $query->select("language");
                }])
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs = $query->jobFilters($query, $requestData);

            $allJobs->orderBy('created_at', 'desc')->paginate(15);
        }
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $allCustomers,
            'all_translators' => $allTranslators,
            'requestdata' => $requestData
        ];
    }
    /** 
     * @return array         
     */
    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $allCustomers = DB::table('users')->where('user_type', '1')->lists('email');
        $allTranslators = DB::table('users')->where('user_type', '2')->lists('email');
        $cuser = Auth::user();

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            // ===================================================
            // Laravel relation can be used there then we can use "when" laravel eloquent method and don't need to write many if conditions
            // ==================================================
            $query = jobs::query()
                ->with(['languages' => function ($query) {
                    $query->select("language");
                }])
                ->where('status', 'pending')
                ->where('ignore_expired', 0)
                ->where('due', '>=', Carbon::now());

            $allJobs = $query->jobFilters($query, $requestData);

            $allJobs->orderBy('created_at', 'desc')->paginate(15);
        }
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $allCustomers,
            'all_translators' => $allTranslators,
            'requestdata' => $requestData
        ];
    }
    /**
     * @return array         
     */
    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
        return [
            'throttles' => $throttles
        ];
    }
    /**
     * @param  int $id   
     * @return array         
     */
    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return [
            'success',
            'Changes saved'
        ];
    }
    /**
     * @param  int $id   
     * @return array         
     */
    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return [
            'success',
            'Changes saved'
        ];
    }
    /**
     * @param  int $id   
     * @return array         
     */
    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return [
            'success',
            'Changes saved'
        ];
    }
    /**
     * @param  object $request   
     * @return array         
     */
    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId);
        $job = $job->toArray();

        $data = [];
        $data['created_at'] = Carbon::now()->format("Y-m-d H:i:s");
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = Carbon::now()->format("Y-m-d H:i:s");
        $data['user_id'] = $userId;
        $data['job_id'] = $jobId;
        $data['cancel_at'] = Carbon::now();

        $dataReopen = [];
        $dataReopen['status'] = 'pending';
        $dataReopen['created_at'] = Carbon::now();
        $dataReopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $dataReopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobId)->update($dataReopen);
            $new_jobid = $jobId;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], Carbon::now()->format("Y-m-d H:i:s"));
            $job['updated_at'] = Carbon::now()->format("Y-m-d H:i:s");
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        Translator::where('job_id', $jobId)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }
    /**
     * @param $query
     * @param $requestData
     * @return array
     */
    private function jobFilters($query, $requestData)
    {
        return $query->when($requestData['lang'], function ($query) use ($requestData) {
            $query->whereIn('from_language_id', $requestData['lang'] ?? []);
        })
            ->when($requestData['status'], function ($query) use ($requestData) {
                $query->whereIn('status', $requestData['status'] ?? []);
            })
            ->when($requestData['customer_email'], function ($query) use ($requestData) {
                $user = DB::table('users')->select('id')->where('email', $requestData['customer_email'])->first();
                $query->where('user_id', '=', $user->id);
            })
            ->when($requestData['translator_email'], function ($query) use ($requestData) {
                $user = DB::table('users')->select('id')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $query->whereIn('id', $allJobIDs ?? []);
                }
            })
            ->when($requestData['filter_timetype'] == "created", function ($query) use ($requestData) {
                $query->when($requestData['from'], function ($q) use ($requestData) {
                    $q->where('created_at', '>=', $requestData["from"]);
                });
                $query->when($requestData['to'], function ($q) use ($requestData) {
                    $q->where('created_at', '<=', $requestData["to"] . " 23:59:00");
                });
                $query->orderBy('created_at', 'desc');
            })
            ->when($requestData['filter_timetype'] == "due", function ($query) use ($requestData) {
                $query->when($requestData['from'], function ($q) use ($requestData) {
                    $q->where('due', '>=', $requestData["from"]);
                });
                $query->when($requestData['to'], function ($q) use ($requestData) {
                    $q->where('due', '<=', $requestData["to"] . " 23:59:00");
                });
                $query->orderBy('due', 'desc');
            })
            ->when($requestData['job_type'], function ($query) use ($requestData) {
                $query->whereIn('job_type', $requestData['job_type'] ?? []);
            })
            ->when($requestData['lang'], function ($query) use ($requestData) {
                $query->whereIn('from_language_id', $requestData['lang'] ?? []);
            })
            ->when($requestData['lang'], function ($query) use ($requestData) {
                $query->whereIn('from_language_id', $requestData['lang'] ?? []);
            });
    }
}
