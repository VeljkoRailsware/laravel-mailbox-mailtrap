<?php

namespace VeljkoRailsware\LaravelMailboxMailtrap\Tests;

use BeyondCode\Mailbox\Facades\Mailbox;
use BeyondCode\Mailbox\InboundEmail;
use BeyondCode\Mailbox\MailboxServiceProvider;
use GuzzleHttp\Psr7\Response;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Orchestra\Testbench\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VeljkoRailsware\LaravelMailboxMailtrap\MailtrapMailboxServiceProvider;

/** No sockets: every SDK request must pass through this fake. */
class SdkFake implements ClientInterface
{
    public static array $paths = [];
    public static $handler;
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        self::$paths[] = $request->getUri()->getPath();
        if (!self::$handler) { throw new \LogicException('Unconfigured SDK fake'); }
        return (self::$handler)($request);
    }
}
class FakeDiscovery implements DiscoveryStrategy
{
    public static function getCandidates($type)
    {
        return $type === ClientInterface::class ? [['class' => SdkFake::class]] : [];
    }
}

/**
 * Feature suite for the Mailtrap driver. See TESTING.md for what each QA id proves
 * and which tests characterise open defects rather than assert the intended contract.
 * Every test prints one `QA <ID> {json}` evidence line to stdout.
 */
class MailtrapFeatureTest extends TestCase
{
    private const INBOX = 4242;

    private array $strategies;
    private array $seen = [];
    private array $downloads = [];
    private int $delay = 0;
    private int $rawStatus = 200;
    private bool $timeout = false;
    private bool $originalMime = false;
    private ?string $rawBody = null;

    protected function getPackageProviders($app)
    {
        return [MailboxServiceProvider::class, MailtrapMailboxServiceProvider::class];
    }
    protected function defineEnvironment($app)
    {
        $app['config']->set('mailbox.driver', 'mailtrap');
        $app['config']->set('mailbox-mailtrap.signing_secret', 'synthetic-qa-secret');
        $app['config']->set('mailbox-mailtrap.api_token', 'synthetic-not-a-token');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport'=>'array']);
        $app['config']->set('mail.from', ['address'=>'qa@example.test', 'name'=>'QA']);
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->strategies = [...Psr18ClientDiscovery::getStrategies()];
        Psr18ClientDiscovery::prependStrategy(FakeDiscovery::class);
        SdkFake::$paths = [];
        SdkFake::$handler = function ($request) {
            if ($this->delay) usleep($this->delay);
            $id = basename($request->getUri()->getPath());
            return new Response(200, ['Content-Type'=>'application/json'], json_encode(['raw_message_url'=>'https://raw.example.test/'.$id]));
        };
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->downloads[] = $request->url();
            if ($this->delay) usleep($this->delay);
            if ($this->timeout) throw new ConnectionException('Synthetic download timeout');
            $id = basename(parse_url($request->url(), PHP_URL_PATH));
            $mime = file_get_contents(__DIR__.'/Fixtures/rich.eml');
            if (!$this->originalMime) $mime = preg_replace('/^Message-ID:.*$/mi', 'Message-ID: <'.$id.'@example.test>', $mime);
            return Http::response($this->rawBody ?? $mime, $this->rawStatus);
        });
        // Use the dependency's real migration, including its actual indexes.
        require_once __DIR__.'/../vendor/beyondcode/laravel-mailbox/database/migrations/create_mailbox_inbound_emails_table.php.stub';
        (new \CreateMailboxInboundEmailsTable)->up();
        Mailbox::to('inbound@example.test', function (InboundEmail $email) {
            $this->seen[] = $email->id();
        });
    }
    protected function tearDown(): void
    {
        Psr18ClientDiscovery::setStrategies($this->strategies);
        SdkFake::$handler = null;
        parent::tearDown();
    }
    private function events(int $n = 1): array
    {
        return array_map(fn($i)=>['event'=>'inbound.message_received','event_id'=>'event-'.$i,'inbox_id'=>self::INBOX,'message_id'=>'m'.$i], range(1,$n));
    }
    private function sendEvents(array $events)
    {
        return $this->sendRaw(json_encode(['events'=>$events]));
    }
    private function sendRaw(string $raw, ?string $signature = null)
    {
        return $this->call('POST', '/laravel-mailbox/mailtrap', [], [], [], [
            'CONTENT_TYPE'=>'application/json',
            'HTTP_MAILTRAP_SIGNATURE'=>$signature ?? hash_hmac('sha256',$raw,'synthetic-qa-secret'),
        ], $raw);
    }
    private function evidence(string $id, array $data): void
    {
        fwrite(STDOUT, "\nQA ".$id.' '.json_encode($data, JSON_UNESCAPED_SLASHES)."\n");
    }

    // ---- Contract tests: these assert the intended behaviour -------------------------------

    public function test_f01_rich_mime_routes_attachments_and_reply(): void
    {
        $this->originalMime = true;
        $this->sendEvents($this->events())->assertOk()->assertSee('handled 1 skipped 0');
        $this->assertCount(1,$this->seen);
        $email = InboundEmail::firstOrFail();
        $this->assertSame('sender@example.test',$email->from());
        $this->assertSame('inbound@example.test',$email->to()[0]->getEmail());
        $this->assertStringContainsString('ünïcode',$email->subject());
        $this->assertNotEmpty($email->text());
        $this->assertStringContainsString('cid:pixel1',$email->html());
        $parts=[];
        foreach ($email->attachments() as $part) {
            $parts[$part->getFilename()] = ['bytes'=>strlen($part->getContent()),'type'=>$part->getContentType(), 'disposition'=>$part->getHeaderValue('Content-Disposition'), 'cid'=>$part->getHeaderValue('Content-ID')];
        }
        $this->assertSame(20047,$parts['invoice-B5.pdf']['bytes']);
        $this->assertSame(70,$parts['pixel.png']['bytes']);
        $this->assertSame('application/pdf',$parts['invoice-B5.pdf']['type']);
        $this->assertSame('inline',$parts['pixel.png']['disposition']);
        $this->assertSame('pixel1',$parts['pixel.png']['cid']);
        $email->reply((new Mailable)->subject('QA reply')->html('<p>Reply</p>'));
        $sent = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('sender@example.test',$sent->getTo()[0]->getAddress());
        $this->assertSame('<'.$email->id().'>',$sent->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->evidence('F01',['routed'=>$this->seen,'parts'=>$parts,'reply_to'=>$sent->getTo()[0]->getAddress(),'reply_header'=>$sent->getHeaders()->get('In-Reply-To')->getBodyAsString()]);
    }
    public function test_f02_inbox_filter_is_strict(): void
    {
        // [configured filter, inbox_id on the event, expected status, expected SDK fetches]
        $cases = [
            [null, self::INBOX, 200, 1],           // no filter: any inbox
            ['', self::INBOX, 200, 1],             // empty filter: any inbox
            [null, 'garbage', 200, 0],             // no filter, but the event id is unusable: skipped
            [null, '0', 200, 0],                   // zero is not an inbox id
            ['0', self::INBOX, 503, 0],            // "0" is not a valid filter: configuration error
            ['0', null, 503, 0],
            ['0', 'garbage', 503, 0],
            [(string) self::INBOX, self::INBOX, 200, 1],
            [(string) self::INBOX, 42, 200, 0],    // another inbox: ignored
            [(string) self::INBOX, self::INBOX.'oops', 200, 0],  // no integer coercion
        ];
        foreach ($cases as [$filter,$inbox,$status,$count]) {
            config(['mailbox-mailtrap.inbox_id'=>$filter]);
            $events=$this->events(); $events[0]['inbox_id']=$inbox;
            $before=count(SdkFake::$paths);
            $this->sendEvents($events)->assertStatus($status);
            $this->assertCount($before+$count,SdkFake::$paths, "filter=".var_export($filter,true)." inbox=".var_export($inbox,true));
            $this->evidence('F02',['filter'=>$filter,'event_inbox'=>$inbox,'status'=>$status,'fetches'=>count(SdkFake::$paths)-$before]);
        }
    }
    public function test_f03_real_five_event_shape(): void
    {
        $payload=json_decode(file_get_contents(__DIR__.'/Fixtures/events.json'),true);
        $this->sendEvents($payload['events'])->assertOk()->assertSee('handled 5 skipped 0');
        $this->assertCount(5,$this->seen);
        $this->assertCount(5,SdkFake::$paths);
        $this->evidence('F03',['fetched'=>count(SdkFake::$paths),'routed'=>count($this->seen)]);
    }
    public function test_f04_signature_route_has_no_basic_auth_middleware(): void
    {
        // Contract: the route is signature-only; mailbox.basic_auth is deliberately not applied.
        config(['mailbox.basic_auth.username'=>'qa','mailbox.basic_auth.password'=>'synthetic-password']);
        $route=app('router')->getRoutes()->getByName('mailbox.mailtrap');
        $this->assertSame([],$route->gatherMiddleware());
        $this->sendEvents([])->assertOk();
        $this->evidence('F04',['middleware'=>$route->gatherMiddleware(),'without_basic_auth'=>200]);
    }
    public function test_e02_sdk_401_and_404_escape_as_500(): void
    {
        // Contract: an SDK failure is a retryable 5xx. It is still coupled to defect 2 (see E01).
        foreach ([401,404] as $status) {
            SdkFake::$handler=fn()=>new Response($status,['Content-Type'=>'application/json'], '{"errors":["synthetic failure"]}');
            $this->sendEvents($this->events())->assertStatus(500);
            $this->evidence('E02',['sdk_status'=>$status,'webhook_status'=>500]);
        }
        $this->assertCount(0,$this->seen);
    }
    public function test_e02_raw_403_and_timeout_return_502(): void
    {
        Log::spy();
        $this->rawStatus=403;
        $this->sendEvents($this->events())->assertStatus(502);
        $this->rawStatus=200; $this->timeout=true;
        $this->sendEvents($this->events())->assertStatus(502);
        $this->assertCount(0,$this->seen);
        Log::shouldHaveReceived('error')->twice();
        $this->evidence('E02',['raw_403'=>502,'synthetic_timeout'=>502,'routed'=>0]);
    }
    public function test_e04_unfixable_input_is_logged_and_skipped(): void
    {
        Log::spy();
        $cases = [
            'other'        => [[['event'=>'other']], 'handled 0 skipped 0'],                          // not ours, ignored silently
            'empty'        => [[], 'handled 0 skipped 0'],
            'missing_id'   => [[['event'=>'inbound.message_received','inbox_id'=>self::INBOX]], 'handled 0 skipped 1'],
            'scalar_event' => [['bad'], 'handled 0 skipped 1'],
            'array_id'     => [[['event'=>'inbound.message_received','inbox_id'=>self::INBOX,'message_id'=>['malformed']]], 'handled 0 skipped 1'],
        ];
        foreach ($cases as $name=>[$events,$body]) {
            $r=$this->sendEvents($events); $r->assertOk()->assertSee($body);
            $this->evidence('E04',['case'=>$name,'status'=>200,'body'=>$r->getContent()]);
        }
        $this->assertCount(0,SdkFake::$paths);
        $this->sendRaw('{broken')->assertOk()->assertSee('handled 0 skipped 1');
        $this->sendRaw('{"events":{"not":"a list"}}')->assertOk()->assertSee('handled 0 skipped 1');
        // A bad event does not poison the good ones in the same batch.
        $mixed=$this->events(); $mixed[]='bad';
        $this->sendEvents($mixed)->assertOk()->assertSee('handled 1 skipped 1');
        $this->assertSame(['m1@example.test'],$this->seen);
        // An SDK response without raw_message_url is skipped, not acknowledged as handled.
        SdkFake::$handler=fn()=>new Response(200,['Content-Type'=>'application/json'],'{}');
        $this->sendEvents($this->events())->assertOk()->assertSee('handled 0 skipped 1');
        Log::shouldHaveReceived('warning')->atLeast()->times(6);
        Log::shouldHaveReceived('error')->once();
        $this->evidence('E04',['malformed_json'=>200,'events_not_list'=>200,'mixed_batch'=>'handled 1 skipped 1','missing_raw_url'=>200,'routed'=>count($this->seen)]);
    }
    public function test_e04_invalid_raw_mime_is_skipped_not_handled(): void
    {
        Log::spy();
        $this->rawBody='not an email';
        $this->sendEvents($this->events())->assertOk()->assertSee('handled 0 skipped 1');
        $this->assertCount(0,$this->seen);
        $this->assertSame(0,InboundEmail::count());
        Log::shouldHaveReceived('error')->once();
        $this->evidence('E04',['invalid_mime_body'=>'handled 0 skipped 1','routed'=>0,'rows'=>0]);
    }
    public function test_a01_signatures_bind_exact_body(): void
    {
        $raw=json_encode(['events'=>$this->events()]);
        foreach (['',str_repeat('a',64),str_repeat('x',64)] as $signature) $this->sendRaw($raw,$signature)->assertStatus(401);
        $this->sendRaw($raw.' ', hash_hmac('sha256',$raw,'synthetic-qa-secret'))->assertStatus(401);
        $this->assertCount(0,SdkFake::$paths);
        $this->sendRaw($raw)->assertOk();
        $this->evidence('A01',['invalid_rejected'=>4,'reject_sdk_calls'=>0,'valid_status'=>200]);
    }
    public function test_a02_empty_and_null_secrets_fail_closed(): void
    {
        foreach (['',null] as $secret) {
            config(['mailbox-mailtrap.signing_secret'=>$secret]);
            $raw=json_encode(['events'=>$this->events()]);
            $this->sendRaw($raw,hash_hmac('sha256',$raw,''))->assertStatus(401);
        }
        $this->assertCount(0,SdkFake::$paths);
        $this->evidence('A02',['empty'=>401,'null'=>401,'sdk_calls'=>0]);
    }
    public function test_a03_non_https_or_credentialed_raw_url_is_refused(): void
    {
        Log::spy();
        foreach (['http://127.0.0.1/private','https://user:secret@raw.example.test/m1','ftp://raw.example.test/m1','not a url'] as $bad) {
            SdkFake::$handler=fn()=>new Response(200,['Content-Type'=>'application/json'],json_encode(['raw_message_url'=>$bad]));
            $this->sendEvents($this->events())->assertStatus(502);
        }
        $this->assertSame([],$this->downloads);
        $this->assertCount(0,$this->seen);
        Log::shouldHaveReceived('error')->times(4);
        $this->evidence('A03',['refused'=>4,'downloads'=>0,'status'=>502]);
    }

    // ---- Characterisation tests: these document OPEN defects (issues #1 and #2) --------------
    // Green here means "the defect is still present as described", not "the behaviour is right".
    // When the defect is fixed, flip the assertion and move the test above this line.

    public function test_e01_partial_batch_retry_duplicates_first_side_effect(): void
    {
        $normal=SdkFake::$handler;
        SdkFake::$handler=fn($request)=>str_ends_with($request->getUri()->getPath(),'/m2') ? new Response(404,['Content-Type'=>'application/json'], '{"errors":["synthetic missing"]}') : $normal($request);
        $this->sendEvents($this->events(3))->assertStatus(500);
        $this->assertSame(['m1@example.test'],$this->seen);
        SdkFake::$handler=$normal;
        $this->sendEvents($this->events(3))->assertOk();
        $this->assertSame(['m1@example.test','m1@example.test','m2@example.test','m3@example.test'],$this->seen);
        $this->assertSame(4,InboundEmail::count());
        $this->evidence('E01',['statuses'=>[500,200],'side_effects'=>$this->seen,'rows'=>InboundEmail::count(),'sdk_calls'=>SdkFake::$paths]);
    }
    public function test_e02_callback_failure_repeats_prior_side_effect(): void
    {
        Mailbox::catchAll(function () { throw new \RuntimeException('Synthetic mailbox failure'); });
        $this->sendEvents($this->events())->assertStatus(500);
        $this->sendEvents($this->events())->assertStatus(500);
        $this->assertCount(2,$this->seen);
        $this->assertSame(0,InboundEmail::count());
        $this->evidence('E02',['callback_failure_status'=>500,'side_effects'=>count($this->seen),'stored_rows'=>InboundEmail::count()]);
    }
    public function test_e05_replays_are_not_deduplicated(): void
    {
        $events=$this->events();
        $this->sendEvents($events)->assertOk();
        $this->sendEvents($events)->assertOk();
        $events[0]['event_id']='different-event';
        $this->sendEvents($events)->assertOk();
        $this->assertCount(3,$this->seen); $this->assertSame(3,InboundEmail::count());
        $this->evidence('E05',['same_message_side_effects'=>count($this->seen),'rows'=>InboundEmail::count()]);
    }
    public function test_e03_twelve_events_are_processed_synchronously(): void
    {
        $this->delay=50000;
        $start=microtime(true);
        $this->sendEvents($this->events(12))->assertOk()->assertSee('handled 12 skipped 0');
        $elapsed=microtime(true)-$start;
        $this->assertCount(12,SdkFake::$paths); $this->assertCount(12,$this->downloads);
        $this->assertGreaterThanOrEqual(1.2,$elapsed);
        $this->evidence('E03',['events'=>12,'sdk_calls'=>12,'downloads'=>12,'fake_delay_seconds_per_call'=>0.05,'elapsed_seconds'=>$elapsed]);
    }
}
