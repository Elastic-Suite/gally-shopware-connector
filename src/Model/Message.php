<?php
declare(strict_types=1);

namespace Gally\ShopwarePlugin\Model;

use Shopware\Core\Framework\Struct\Struct;

class Message extends Struct
{
    public function __construct(private string $type, private string $content)
    {
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
