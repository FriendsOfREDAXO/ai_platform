<?php

namespace FriendsOfRedaxo\AiPlatform\Bridge\OpenAiCompatible;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Selbstgehostete/kompatible Server (Open WebUI, LiteLLM, vLLM, LM Studio,
 * text-generation-webui, ...) haben keine feste, im Voraus bekannte
 * Modell-Liste -- anders als Ollama's eigener ModelCatalog (Bridge, feste
 * Namens-Praefix-Liste) akzeptiert dieser Katalog JEDEN Modellnamen, den der
 * Nutzer im Profil eingetragen hat. Faehigkeiten sind bewusst grosszuegig
 * (Text + Bild-Input) angenommen -- ein Modell, das das nicht kann, liefert
 * ohnehin einen Fehler vom Server selbst zurueck.
 */
final class ModelCatalog implements ModelCatalogInterface
{
    public function getModel(string $modelName): Model
    {
        return new Model($modelName, [
            Capability::INPUT_MESSAGES,
            Capability::INPUT_TEXT,
            Capability::INPUT_IMAGE,
            Capability::INPUT_MULTIMODAL,
            Capability::OUTPUT_TEXT,
            Capability::OUTPUT_STRUCTURED,
        ]);
    }

    public function getModels(): array
    {
        return [];
    }
}
