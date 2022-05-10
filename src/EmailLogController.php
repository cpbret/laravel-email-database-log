<?php

namespace Dmcbrn\LaravelEmailDatabaseLog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Dmcbrn\LaravelEmailDatabaseLog\Events\EventFactory;

class EmailLogController extends Controller {

    public function index(Request $request)
    {
        //validate
        $request->validate([
            'filterEmail' => 'string',
            'filterSubject' => 'string',
        ]);

        //get emails
        $filterEmail = $request->filterEmail;
        $filterSubject = $request->filterSubject;
        $emails = EmailLog::with([
                'events' => function($q) {
                    $q->select('messageId','created_at','event');
                }
            ])
            ->select('id','messageId','date','from','to','subject')
            ->when($filterEmail, function($q) use($filterEmail) {
                return $q->where('to','like','%'.$filterEmail.'%');
            })
            ->when($filterSubject, function($q) use($filterSubject) {
                return $q->where('subject','like','%'.$filterSubject.'%');
            })
            ->orderBy('id','desc')
            ->paginate(20);

        //return
        return view('email-logger::index', compact('emails','filterEmail','filterSubject'));
    }

    public function show($id)
    {
        $email = EmailLog::find($id);

        return view('email-logger::show', compact('email'));
    }

    public function fetchAttachment($id,$attachment)
    {
        $email = EmailLog::select('id','attachments')->find($id);
        $attachmentFullPath = explode(', ',$email->attachments)[$attachment];

        $file = Storage::get(urldecode($attachmentFullPath));
        $mimeType = Storage::mimeType(urldecode($attachmentFullPath));

        return response($file, 200)->header('Content-Type', $mimeType);
    }

    public function createEvent(Request $request)
    {
    	$event = EventFactory::create('mailgun');

    	//check if event is valid
    	if(!$event)
            return response('Error: Unsupported Service', 400)->header('Content-Type', 'text/plain');

        //validate the $request data for this $event
        if(!$event->verify($request))
            return response('Error: verification failed', 400)->header('Content-Type', 'text/plain');

        //save event
        return $event->saveEvent($request);
    }
    
    public function deleteOldEmails(Request $request)
    {
        //validate
        $request->validate([
            'date' => 'required|date',
        ]);

        //get emails
        $emails = EmailLog::select('id', 'date', 'messageId')->where('date', '<=', date("c", strtotime($request->date)))->get();

        //delete attachments & emails
        foreach ($emails as $email) { Storage::disk(config('email_log.disk'))->deleteDirectory($email->messageId); }
        $deleted = EmailLog::destroy($emails->pluck('id'));

        //return
        return redirect(route('email-log'))->with('status', 'Deleted ' . $deleted . ' emails logged before ' . date("r", strtotime($request->date)));
    }
}
