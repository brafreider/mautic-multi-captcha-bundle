<?php declare(strict_types=1);

namespace MauticPlugin\MauticMultiCaptchaBundle\Tests\EventListener;

use MauticPlugin\MauticMultiCaptchaBundle\EventListener\CapFormSubscriber;
use MauticPlugin\MauticMultiCaptchaBundle\Service\CapClient;
use MauticPlugin\MauticMultiCaptchaBundle\Integration\CapIntegration;
use MauticPlugin\MauticMultiCaptchaBundle\CaptchaEvents;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\Event\ValidationEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CapFormSubscriber
 *
 * Unlike ALTCHA (whose token is bound to the field's own form value via
 * ValidationEvent::getValue()), the Cap widget's redeemed token arrives as
 * a fixed-name hidden field ("cap-token") submitted alongside the rest of
 * the Mautic form, so these tests read/write $_POST directly.
 */
class CapFormSubscriberTest extends TestCase {

    protected function tearDown(): void {
        unset($_POST['cap-token']);
    }

    /**
     * @test
     */
    public function testSubscribedEvents(): void {
        $events = CapFormSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(FormEvents::FORM_ON_BUILD, $events);
        $this->assertArrayHasKey(CaptchaEvents::CAP_ON_FORM_VALIDATE, $events);
    }

    /**
     * @test
     */
    public function testOnFormBuildAddsFieldWhenFullyConfigured(): void {
        $subscriber = $this->createSubscriber(
            $this->createMock(CapClient::class),
            $this->createMock(LeadModel::class),
            [
                'server_url' => 'https://cap.example.com',
                'site_key'   => 'site-key-123',
                'secret_key' => 'secret-abc'
            ]
        );

        $event = $this->createMock(FormBuilderEvent::class);

        $addedField = null;
        $event->expects($this->once())
            ->method('addFormField')
            ->with('plugin.cap', $this->callback(function ($config) use (&$addedField) {
                $addedField = $config;
                return true;
            }));

        $event->expects($this->once())
            ->method('addValidator')
            ->with('plugin.cap.validator', [
                'eventName' => CaptchaEvents::CAP_ON_FORM_VALIDATE,
                'fieldType' => 'plugin.cap'
            ]);

        $subscriber->onFormBuild($event);

        $this->assertNotNull($addedField);
        $this->assertEquals('https://cap.example.com', $addedField['server_url']);
        $this->assertEquals('site-key-123', $addedField['site_key']);
    }

    /**
     * Property Test: onFormBuild is a no-op when any required key is missing
     *
     * For every combination that omits at least one of server_url, site_key
     * or secret_key, the field/validator must NOT be registered - the widget
     * must never be shown against a half-configured Cap instance.
     *
     * @test
     */
    public function testOnFormBuildSkipsFieldWhenNotFullyConfigured(): void {
        $incompleteConfigs = [
            [],
            ['server_url' => 'https://cap.example.com'],
            ['server_url' => 'https://cap.example.com', 'site_key' => 'site-key-123'],
            ['site_key' => 'site-key-123', 'secret_key' => 'secret-abc'],
        ];

        foreach ($incompleteConfigs as $keys) {
            $subscriber = $this->createSubscriber(
                $this->createMock(CapClient::class),
                $this->createMock(LeadModel::class),
                $keys
            );

            $event = $this->createMock(FormBuilderEvent::class);
            $event->expects($this->never())->method('addFormField');
            $event->expects($this->never())->method('addValidator');

            $subscriber->onFormBuild($event);
        }
    }

    /**
     * @test
     */
    public function testOnFormValidatePassesWhenTokenVerifies(): void {
        $_POST['cap-token'] = 'sitekey:id:secret';

        $capClient = $this->createMock(CapClient::class);
        $capClient->expects($this->once())
            ->method('verify')
            ->with('sitekey:id:secret')
            ->willReturn(true);

        $subscriber = $this->createSubscriber($capClient, $this->createMock(LeadModel::class));

        $event = $this->createMock(ValidationEvent::class);
        $event->expects($this->never())->method('failedValidation');

        $subscriber->onFormValidate($event);
    }

    /**
     * @test
     */
    public function testOnFormValidateTreatsMissingTokenAsEmptyString(): void {
        unset($_POST['cap-token']);

        $capClient = $this->createMock(CapClient::class);
        $capClient->expects($this->once())
            ->method('verify')
            ->with('')
            ->willReturn(false);

        $subscriber = $this->createSubscriber($capClient, $this->createMock(LeadModel::class));

        $event = $this->createMock(ValidationEvent::class);
        $event->method('failedValidation');

        $subscriber->onFormValidate($event);
    }

    /**
     * @test
     */
    public function testOnFormValidateIsNoOpWhenNotConfigured(): void {
        $capClient = $this->createMock(CapClient::class);
        $capClient->expects($this->never())->method('verify');

        $subscriber = $this->createSubscriber($capClient, $this->createMock(LeadModel::class), []);

        $event = $this->createMock(ValidationEvent::class);
        $event->expects($this->never())->method('failedValidation');

        $subscriber->onFormValidate($event);
    }

    /**
     * Property Test: Lead cleanup after failed Cap validation
     *
     * Mirrors AltchaFormSubscriberTest::testLeadCleanupAfterFailedValidation -
     * when verification fails for a newly created lead, the subscriber must
     * register a LEAD_POST_SAVE -> kernel.terminate chain that deletes it.
     *
     * @test
     */
    public function testLeadCleanupAfterFailedValidation(): void {
        $_POST['cap-token'] = 'sitekey:id:bad-secret';

        $registeredListeners = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('addListener')
            ->willReturnCallback(function ($eventName, $listener, $priority = 0) use (&$registeredListeners) {
                $registeredListeners[] = ['event' => $eventName, 'listener' => $listener, 'priority' => $priority];
            });

        $capClient = $this->createMock(CapClient::class);
        $capClient->method('verify')->willReturn(false);

        $leadModel = $this->createMock(LeadModel::class);
        $leadDeleteCalled = false;
        $leadModel->method('getEntity')->willReturn($this->createMock(Lead::class));
        $leadModel->method('deleteEntity')
            ->willReturnCallback(function () use (&$leadDeleteCalled) {
                $leadDeleteCalled = true;
            });

        $subscriber = $this->createSubscriber($capClient, $leadModel, [
            'server_url' => 'https://cap.example.com',
            'site_key'   => 'site-key-123',
            'secret_key' => 'secret-abc'
        ], $eventDispatcher);

        $validationEvent = $this->createMock(ValidationEvent::class);
        $validationFailedCalled = false;
        $validationEvent->method('failedValidation')
            ->willReturnCallback(function () use (&$validationFailedCalled) {
                $validationFailedCalled = true;
            });

        $subscriber->onFormValidate($validationEvent);

        $this->assertTrue($validationFailedCalled);

        $leadPostSaveListener = null;
        foreach ($registeredListeners as $listener) {
            if ($listener['event'] === LeadEvents::LEAD_POST_SAVE) {
                $leadPostSaveListener = $listener;
                break;
            }
        }

        $this->assertNotNull($leadPostSaveListener, 'LEAD_POST_SAVE listener should be registered');
        $this->assertEquals(-255, $leadPostSaveListener['priority']);

        $lead = new Lead();
        $lead->setId(123);
        $leadEvent = new LeadEvent($lead, true);

        $registeredListeners = [];
        ($leadPostSaveListener['listener'])($leadEvent);

        $kernelTerminateListener = null;
        foreach ($registeredListeners as $listener) {
            if ($listener['event'] === 'kernel.terminate') {
                $kernelTerminateListener = $listener;
                break;
            }
        }

        $this->assertNotNull($kernelTerminateListener, 'kernel.terminate listener should be registered');

        ($kernelTerminateListener['listener'])();

        $this->assertTrue($leadDeleteCalled, 'Lead should be deleted after kernel.terminate');
    }

    /**
     * @test
     */
    public function testLeadCleanupSkipsExistingLeads(): void {
        $_POST['cap-token'] = 'sitekey:id:bad-secret';

        $registeredListeners = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('addListener')
            ->willReturnCallback(function ($eventName, $listener, $priority = 0) use (&$registeredListeners) {
                $registeredListeners[] = ['event' => $eventName, 'listener' => $listener, 'priority' => $priority];
            });

        $capClient = $this->createMock(CapClient::class);
        $capClient->method('verify')->willReturn(false);

        $leadModel = $this->createMock(LeadModel::class);
        $leadDeleteCalled = false;
        $leadModel->method('deleteEntity')
            ->willReturnCallback(function () use (&$leadDeleteCalled) {
                $leadDeleteCalled = true;
            });

        $subscriber = $this->createSubscriber($capClient, $leadModel, [
            'server_url' => 'https://cap.example.com',
            'site_key'   => 'site-key-123',
            'secret_key' => 'secret-abc'
        ], $eventDispatcher);

        $validationEvent = $this->createMock(ValidationEvent::class);
        $validationEvent->method('failedValidation');

        $subscriber->onFormValidate($validationEvent);

        $leadPostSaveListener = null;
        foreach ($registeredListeners as $listener) {
            if ($listener['event'] === LeadEvents::LEAD_POST_SAVE) {
                $leadPostSaveListener = $listener;
                break;
            }
        }

        $this->assertNotNull($leadPostSaveListener);

        $lead = new Lead();
        $lead->setId(456);
        $leadEvent = new LeadEvent($lead, false); // not new

        $registeredListeners = [];
        ($leadPostSaveListener['listener'])($leadEvent);

        $hasKernelTerminate = false;
        foreach ($registeredListeners as $listener) {
            if ($listener['event'] === 'kernel.terminate') {
                $hasKernelTerminate = true;
            }
        }

        $this->assertFalse($hasKernelTerminate);
        $this->assertFalse($leadDeleteCalled);
    }

    /**
     * Helper: Create a CapFormSubscriber with mocked dependencies.
     */
    private function createSubscriber(
        CapClient $capClient,
        LeadModel $leadModel,
        array $keys = ['server_url' => 'https://cap.example.com', 'site_key' => 'site-key-123', 'secret_key' => 'secret-abc'],
        ?EventDispatcherInterface $eventDispatcher = null
    ): CapFormSubscriber {
        $integrationHelper = $this->createMock(IntegrationHelper::class);

        $integration = $this->createMock(AbstractIntegration::class);
        $integration->method('getKeys')->willReturn($keys);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Cap CAPTCHA verification failed.');
        $integration->method('getTranslator')->willReturn($translator);

        $integrationHelper->method('getIntegrationObject')
            ->with(CapIntegration::INTEGRATION_NAME)
            ->willReturn($integration);

        return new CapFormSubscriber(
            $eventDispatcher ?? $this->createMock(EventDispatcherInterface::class),
            $capClient,
            $leadModel,
            $integrationHelper
        );
    }

}
