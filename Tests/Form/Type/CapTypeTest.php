<?php declare(strict_types=1);

namespace MauticPlugin\MauticMultiCaptchaBundle\Tests\Form\Type;

use MauticPlugin\MauticMultiCaptchaBundle\Form\Type\CapType;
use MauticPlugin\MauticMultiCaptchaBundle\Integration\CapIntegration;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CapType
 *
 * Cap always combines its proof-of-work and instrumentation challenges (both
 * are configured server-side on the Cap Standalone instance), so this form
 * type intentionally exposes no per-field options - these tests guard that
 * the type still builds a valid, submittable form.
 */
class CapTypeTest extends TestCase {

    private FormFactoryInterface $formFactory;

    protected function setUp(): void {
        $this->formFactory = Forms::createFormFactoryBuilder()->getFormFactory();
    }

    /**
     * @test
     */
    public function testFormBuildsWithoutFields(): void {
        $form = $this->formFactory->create(CapType::class);

        $form->submit([]);

        $this->assertTrue($form->isSubmitted());
        $this->assertTrue($form->isValid());
        $this->assertCount(0, $form->all());
    }

    /**
     * @test
     */
    public function testBlockPrefixMatchesIntegrationName(): void {
        $type = new CapType();

        $this->assertEquals(CapIntegration::INTEGRATION_NAME, $type->getBlockPrefix());
    }

    /**
     * @test
     */
    public function testFormRespectsCustomAction(): void {
        $form = $this->formFactory->create(CapType::class, null, [
            'action' => '/some/custom/action'
        ]);

        $this->assertEquals('/some/custom/action', $form->getConfig()->getAction());
    }

}
