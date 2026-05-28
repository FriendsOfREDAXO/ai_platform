<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi\Whisper;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface Task
{
    public const TRANSCRIPTION = 'transcription';
    public const TRANSLATION = 'translation';
}
