<?php
namespace App\Livewire\Hr;

use App\Helpers\Jalali;
use App\Jobs\{SyncKasraAttendance,SyncKasraDailyRange,SyncKasraPersonalAttendance};
use App\Models\{AttendanceMapping,KasraConnection,KasraPersonalConnection,KasraRecord};
use App\Services\Hr\{AttendanceNotice,AttendanceWorkspaceReport};
use App\Services\Hr\Kasra\{KasraDailyReport,KasraException,KasraPersonalService};
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class AttendanceHours extends Component
{
    use WithPagination;
    public string $day='';
    public string $person='';
    public string $search='';
    public string $from='';
    public string $to='';
    public bool $settings=false;
    public bool $personalForm=false;
    public bool $noticeOpen=false;
    public string $noticeMode='none';
    public string $noticeText='';
    public string $message='';
    #[Locked] public array $noticeReview=[];
    #[Locked] public ?array $range=null;
    #[Locked] public array $historicalReports=[];

    public function mount():void
    {
        $this->authorizeWorkspace();
        $this->day=KasraDailyReport::jalali(now('Asia/Tehran'));
        if($this->canManage())$this->applyDay();
        else{$this->setMonthRange('current');$this->applyFilters(false);}
        $this->poll();
    }

    public function hydrate():void {$this->authorizeWorkspace();$this->sanitizeHistory();}
    private function authorizeWorkspace():void {abort_unless(auth()->check()&&auth()->user()->status==='active',403);}
    public function canManage():bool
    {
        $u=auth()->user();return $u&&($u->hasAnyRole(['admin','founder'])||$u->can('attendance.manage')||$u->can('users.manage'));
    }
    private function managerConnection():?KasraConnection
    {
        return $this->canManage()?KasraConnection::where('configured_by',auth()->id())->where('enabled',true)->first():null;
    }
    private function personalConnection():?KasraPersonalConnection
    {
        return KasraPersonalConnection::where('user_id',auth()->id())->where('enabled',true)->first();
    }
    private function roster(?KasraConnection $c)
    {
        return $c?KasraRecord::where('connection_id',$c->id)->where('generation',$c->generation)->where('kind','monthly')
            ->where('snapshot_id',$c->snapshot_id)->orderBy('person_name')->get(['id','personnel_code','person_name']):collect();
    }
    private function authorizePerson(string $code):KasraConnection
    {
        $this->authorizeWorkspace();abort_unless($this->canManage(),403);$c=$this->managerConnection();
        abort_unless($c&&$code!==''&&KasraRecord::where('connection_id',$c->id)->where('generation',$c->generation)->where('kind','monthly')->where('snapshot_id',$c->snapshot_id)->where('personnel_code',$code)->exists(),422);return $c;
    }
    /** The employee fallback is restricted to an explicit, unique local mapping and the current authorized roster. */
    private function ownOrganization():array
    {
        $m=AttendanceMapping::where('user_id',auth()->id())->get();if($m->count()!==1)return [null,''];
        $code=(string)$m->first()->personnel_code;
        foreach(KasraConnection::where('enabled',true)->orderBy('id')->get() as $c)
            if(KasraRecord::where('connection_id',$c->id)->where('generation',$c->generation)->where('kind','monthly')->where('snapshot_id',$c->snapshot_id)->where('personnel_code',$code)->exists())return [$c,$code];
        return [null,''];
    }
    private function sanitizeHistory():void
    {
        $c=$this->personalConnection();
        $this->historicalReports=array_filter($this->historicalReports,fn($r)=>$c&&(int)($r['user_id']??0)===(int)auth()->id()
            &&(int)($r['connection_id']??0)===(int)$c->id&&($r['generation']??'')===$c->generation);
    }
    public function poll():void
    {
        $this->authorizeWorkspace();
        if(!$this->canManage()){
            $c=$this->personalConnection();if($c&&!$c->next_attempt_at?->isFuture()&&Cache::add($c->cacheKey().':hours-poll',true,45))
                SyncKasraPersonalAttendance::dispatch((string)tenant('id'),(int)auth()->id());
        }
    }
    public function updatedSearch():void {$this->search=mb_substr(trim($this->search),0,80);$this->resetPage();}
    public function applyDay():void
    {
        $this->authorizeWorkspace();abort_unless($this->canManage(),403);
        if(!app(AttendanceWorkspaceReport::class)->date($this->day)){$this->addError('day',__('روز شمسی معتبر انتخاب کنید.'));return;}
        $this->person='';$this->from=$this->to=$this->day;$this->applyFilters(false);
    }
    public function openPerson(string $code):void
    {
        $this->authorizePerson($code);$this->person=$code;
        $anchor=app(AttendanceWorkspaceReport::class)->date($this->day)??now('Asia/Tehran');
        $this->setMonthRange('current',$anchor);$this->applyFilters(false);
    }
    public function closePerson():void {$this->authorizeWorkspace();abort_unless($this->canManage(),403);$this->person='';$this->applyDay();}
    private function setMonthRange(string $choice,?Carbon $anchor=null):void
    {
        [$start,$end]=app(AttendanceWorkspaceReport::class)->month($choice,$anchor);
        $this->from=KasraDailyReport::jalali(Carbon::parse($start,'Asia/Tehran'));
        $this->to=KasraDailyReport::jalali(Carbon::parse($end,'Asia/Tehran'));
    }
    public function chooseMonth(string $choice):void
    {
        $this->authorizeWorkspace();abort_unless(in_array($choice,['current','previous'],true),422);
        if($this->canManage())$this->authorizePerson($this->person);
        $this->setMonthRange($choice);$this->applyFilters();
    }
    public function applyFilters(bool $loadPersonal=true):void
    {
        $this->authorizeWorkspace();$this->validate(['from'=>'required|string|max:10','to'=>'required|string|max:10','person'=>'nullable|string|max:30']);
        $report=app(AttendanceWorkspaceReport::class);$start=$report->date($this->from);$end=$report->date($this->to);
        if(!$start||!$end||$end->lt($start)||$start->diffInDays($end)>31){$this->addError('from',__('بازهٔ معتبر تا ۳۲ روز انتخاب کنید.'));return;}
        $manager=$this->canManage();
        if(!$manager)abort_unless($this->person==='',403);
        if($manager&&$this->person===''){
            if(!$start->isSameDay($end)){$this->addError('from',__('گزارش کلی برای یک روز است؛ برای تاریخچه، یک نفر را انتخاب کنید.'));return;}
            $this->day=$this->from;
        }
        if($manager&&$this->person!=='')$this->authorizePerson($this->person);
        $this->resetErrorBag();$this->range=['start'=>$start->toDateString(),'end'=>$end->toDateString(),'person'=>$manager?$this->person:''];$this->resetPage();
        if($manager){$c=$this->managerConnection();$code=$this->person;}
        else{[$c,$code]=$this->ownOrganization();if($loadPersonal)$this->loadOwnMonths();}
        if($c){$range=array_replace($this->range,['person'=>$code]);$key=$c->cacheKey().':workspace:'.hash('sha256',json_encode($range));
            if(Cache::add($key,true,45))SyncKasraDailyRange::dispatch((string)tenant('id'),$c->id,$c->generation,$range);}
    }
    private function loadOwnMonths():void
    {
        $c=$this->personalConnection();if(!$c||!$this->range)return;$this->sanitizeHistory();$helper=app(AttendanceWorkspaceReport::class);
        $months=[];foreach($helper->days($this->range['start'],$this->range['end']) as $d)$months[substr($d['date'],0,7)]=str_replace('/','-',substr($d['date'],0,7));
        $snapshot=$c->snapshot??[];$snapshotMonth=$helper->reportMonth($snapshot);
        foreach($months as $month){
            if($snapshotMonth===$month)continue;
            $periods=collect($snapshot['periods']??[])->filter(fn($p)=>$helper->matchesMonth((string)($p['title']??''),$month));
            if($periods->count()!==1){$this->addError('report',__('دورهٔ این ماه در پاسخ حساب شخصی مشخص نیست؛ اطلاعات موجود و گزارش سازمانیِ متعلق به خودتان نمایش داده می‌شود.'));continue;}
            try{
                $period=(string)$periods->first()['id'];$report=app(KasraPersonalService::class)->report($c,$period);
                if((string)($report['monthly']['period']??'')!==$period)throw new KasraException(__('دورهٔ پاسخ کسرا با انتخاب شما یکسان نیست.'));
                $people=$report['monthly']['people']??[];
                if(count($people)>1||(isset($people[0]['code'])&&(string)$people[0]['code']!==(string)$c->personnel_code))throw new KasraException(__('هویت گزارش شخصی منطبق نیست.'));
                $this->historicalReports[$month]=['user_id'=>auth()->id(),'connection_id'=>$c->id,'generation'=>$c->generation,'report'=>['monthly'=>$report['monthly']??[],'daily'=>$report['daily']??[]]];
            }catch(KasraException $e){$this->addError('report',$e->getMessage());break;}
        }
        $this->historicalReports=array_slice($this->historicalReports,-3,null,true);
    }
    public function refreshHours():void
    {
        $this->authorizeWorkspace();$this->poll();
        if($this->canManage()){
            $c=$this->managerConnection();if($c&&Cache::add($c->cacheKey().':manual-sync',true,15))SyncKasraAttendance::dispatch((string)tenant('id'),$c->id);
        }
        $this->applyFilters();$this->message=__('تازه‌سازی ثبت شد؛ ساعت‌ها پس از پاسخ کسرا به‌روز می‌شوند.');
    }

    public function previewNotice(AttendanceNotice $service): void
    {
        $this->validate(['noticeMode' => 'required|in:none,available,offline,remote,custom', 'noticeText' => 'nullable|string|max:500']);
        if ($this->noticeMode === 'none') { $this->noticeOpen = false; return; }
        $text = $this->noticeMode === 'custom' ? trim($this->noticeText) : AttendanceNotice::MESSAGES[$this->noticeMode];
        if ($text === '') { $this->addError('noticeText', __('متن پیام را بنویسید.')); return; }
        $this->noticeReview = ['key' => (string) Str::uuid(), 'day' => now('Asia/Tehran')->toDateString(), 'mode' => $this->noticeMode,
            'text' => $text, 'recipients' => $service->recipients(auth()->user())->pluck('id')->map(fn ($id) => (int) $id)->all()];
    }

    public function sendNotice(AttendanceNotice $service): void
    {
        abort_unless($this->noticeReview !== [], 409);
        $service->send(auth()->user(), $this->noticeReview);
        $this->noticeReview = [];
        $this->noticeOpen = false;
        $this->message = __('وضعیت شما برای همکاران ارسال شد.');
    }

    public function editNotice(): void { $this->noticeReview = []; }

    public function closeNotice(): void { $this->noticeOpen = false; $this->noticeReview = []; }

    public function render()
    {
        $this->authorizeWorkspace();$this->sanitizeHistory();$manager=$this->canManage();
        if(!$manager)abort_unless($this->person==='',403);
        $helper=app(AttendanceWorkspaceReport::class);$c=$this->managerConnection();$roster=$this->roster($c);
        $personal=$this->personalConnection();$rowsByDate=[];$overview=collect();$selected=null;$official=null;
        $overviewTotal=0;
        $today=now('Asia/Tehran')->toDateString();$todayPayload=null;$balance=[];
        $range=$this->range??['start'=>$today,'end'=>$today,'person'=>''];
        if($manager&&$this->person===''){
            $day=$helper->date($this->day);$dayKey=$day?->toDateString()??$today;
            $needle=(new \App\Services\Hr\Kasra\KasraParser)->normalize($this->search);
            $filtered=$roster->filter(fn($r)=>$needle===''||str_contains((new \App\Services\Hr\Kasra\KasraParser)->normalize($r->person_name.' '.$r->personnel_code),$needle));
            $overviewTotal=$filtered->count();$pagePeople=$filtered->forPage($this->getPage(),50);
            $codes=$pagePeople->pluck('personnel_code');
            $daily=$c?KasraRecord::where('connection_id',$c->id)->where('generation',$c->generation)->where('kind','daily')
                ->where('period',$dayKey)->whereIn('personnel_code',$codes)->get()->keyBy('personnel_code'):collect();
            $overview=$pagePeople->map(fn($r)=>['code'=>(string)$r->personnel_code,'name'=>$r->person_name,'payload'=>isset($daily[$r->personnel_code])?$helper->normalize($daily[$r->personnel_code]->payload):null])->values();
        }elseif($manager){
            $c=$this->authorizePerson($this->person);$selected=$roster->first(fn($r)=>(string)$r->personnel_code===$this->person);if($selected)$selected->refresh();
            foreach(KasraRecord::where('connection_id',$c->id)->where('generation',$c->generation)->where('kind','daily')->where('personnel_code',$this->person)->whereBetween('period',[$range['start'],$range['end']])->get() as $r)$rowsByDate[$r->period]=$helper->normalize($r->payload);
            if($selected&&$helper->matchesMonth((string)($selected->payload['period_title']??''),Jalali::monthKey(Carbon::parse($range['start'],'Asia/Tehran'))))$official=$selected->payload['metrics']??null;
        }else{
            [$org,$code]=$this->ownOrganization();
            if($personal&&(string)$personal->personnel_code!==$code){$org=null;$code='';}
            if($org){
                foreach(KasraRecord::where('connection_id',$org->id)->where('generation',$org->generation)->where('kind','daily')->where('personnel_code',$code)
                    ->where(fn($q)=>$q->whereBetween('period',[$range['start'],$range['end']])->orWhere('period',$today))->get() as $r){$payload=$helper->normalize($r->payload);$rowsByDate[$r->period]=$payload;if($r->period===$today)$todayPayload=$payload;}
                $ownMonthly=KasraRecord::where('connection_id',$org->id)->where('generation',$org->generation)->where('kind','monthly')->where('snapshot_id',$org->snapshot_id)->where('personnel_code',$code)->first();
                if($ownMonthly&&$helper->matchesMonth((string)($ownMonthly->payload['period_title']??''),Jalali::monthKey(Carbon::parse($range['start'],'Asia/Tehran'))))$official=$ownMonthly->payload['metrics']??null;
            }
            $reports=array_column($this->historicalReports,'report');
            if($personal)$reports[]=$personal->snapshot??[];
            $selectedMonth=Jalali::monthKey(Carbon::parse($range['start'],'Asia/Tehran'));
            foreach($reports as $report){
                foreach($report['daily']['rows']??[] as $row){$d=$helper->date((string)($row['date']??''));if(!$d)continue;$payload=$helper->normalize($row);$key=$d->toDateString();$rowsByDate[$key]=$payload;if($key===$today)$todayPayload=$payload;}
                if($helper->reportMonth($report)===$selectedMonth||$helper->matchesMonth((string)($report['monthly']['title']??''),$selectedMonth))$official=$report['monthly']['people'][0]['metrics']??$official;
            }
            $parser=new \App\Services\Hr\Kasra\KasraParser;
            $balance=collect($personal?->snapshot['dashboard']['metrics']??[])->filter(function($m)use($parser){$name=$parser->normalize((string)($m['name']??''));return str_contains($name,'مانده')&&(str_contains($name,'مرخصی')||str_contains($name,'کاردکس')||str_contains($name,'استحقاقی'));})->values()->all();
        }
        $dates=$helper->days($range['start'],$range['end']);
        $rowsByDate=array_filter($rowsByDate,fn($key)=>$key>=$range['start']&&$key<=$range['end'],ARRAY_FILTER_USE_KEY);
        $overviewPage=new \Illuminate\Pagination\LengthAwarePaginator($overview,$overviewTotal,50,$this->getPage());
        $pastDays=array_filter($dates,fn($d)=>!$d['future']);$received=count(array_filter($pastDays,fn($d)=>isset($rowsByDate[$d['key']])));
        return view('livewire.hr.attendance-hours',[
            'manager'=>$manager,'connection'=>$c,'personalConnection'=>$personal,'overview'=>$overviewPage,'selected'=>$selected,
            'dates'=>$dates,'matrix'=>$rowsByDate,'fields'=>AttendanceWorkspaceReport::FIELDS,'todayPayload'=>$todayPayload,
            'balance'=>$balance,'totals'=>$helper->totals($rowsByDate,$range,$official),'receivedDays'=>$received,'pastDayCount'=>count($pastDays),
            'recipients'=>$this->noticeReview?app(AttendanceNotice::class)->recipients(auth()->user())->whereIn('id',$this->noticeReview['recipients'])->get(['id','name']):collect(),
        ]);
    }
}
