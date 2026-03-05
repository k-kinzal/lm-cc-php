<?php

declare(strict_types=1);

class OrderProcessor
{
    public function process(array $order): array
    {
        $errors = [];

        if (!isset($order['items'])) {
            return ['error' => 'No items'];
        }

        foreach ($order['items'] as $item) {
            if ($item['quantity'] <= 0) {
                $errors[] = 'Invalid quantity';
                continue;
            }

            switch ($item['type']) {
                case 'physical':
                    if (!isset($item['weight'])) {
                        $errors[] = 'Missing weight';
                    } else {
                        if ($item['weight'] > 100) {
                            try {
                                $this->handleOversized($item);
                            } catch (\RuntimeException $e) {
                                $errors[] = $e->getMessage();
                            }
                        }
                    }
                    break;
                case 'digital':
                    if (!isset($item['url'])) {
                        $errors[] = 'Missing URL';
                    }
                    break;
                default:
                    $errors[] = 'Unknown type: ' . $item['type'];
                    break;
            }
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        return ['status' => 'processed'];
    }

    private function handleOversized(array $item): void
    {
        if ($item['weight'] > 500) {
            throw new \RuntimeException('Too heavy');
        }
    }
}
