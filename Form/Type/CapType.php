<?php declare(strict_types=1);

namespace MauticPlugin\MauticMultiCaptchaBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

use MauticPlugin\MauticMultiCaptchaBundle\Integration\CapIntegration;

/**
 * <h1>Class CapType</h1>
 *
 * Cap always combines its proof-of-work and instrumentation challenges, and
 * both are fully configured server-side on the self-hosted Cap Standalone
 * instance, so there are no per-field options to expose here.
 *
 * @package MauticPlugin\MauticMultiCaptchaBundle\Form\Type
 *
 * @authors see: composer.json
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
class CapType extends AbstractType {

    /** {@inheritDoc} */
    public function buildForm(FormBuilderInterface $builder, array $options) {
        if(!empty($options["action"]))
            $builder->setAction($options["action"]);
    }

    /** {@inheritDoc} */
    public function getBlockPrefix() {
        return CapIntegration::INTEGRATION_NAME;
    }

}
