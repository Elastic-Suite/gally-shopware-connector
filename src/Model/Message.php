<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Model;

use Shopware\Core\Framework\Struct\Struct;

class Message extends Struct
{
    /** @var string */
    private $type;

    /** @var string */
    private $content;

    public function __construct(string $type, string $content)
    {
        $this->type = $type;
        $this->content = $content;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }
}
