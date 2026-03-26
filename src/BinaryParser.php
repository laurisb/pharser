<?php

declare(strict_types=1);

namespace App;

use function ord;
use function strlen;
use function substr;

final class BinaryParser
{
    /**
     * @return array{type: string, datasize: int}
     */
    public static function parseBlobHeader(string $data): array
    {
        $pos = 0;
        $len = strlen($data);
        $type = '';
        $size = 0;

        while ($pos < $len) {
            $tag = self::readVarint($data, $pos);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            if ($wire === 2) {
                $length = self::readVarint($data, $pos);

                if ($field === 1) {
                    $type = substr($data, $pos, $length);
                }

                $pos += $length;
            } elseif ($wire === 0) {
                $value = self::readVarint($data, $pos);

                if ($field === 3) {
                    $size = $value;
                }
            } elseif ($wire === 1) {
                $pos += 8;
            } elseif ($wire === 5) {
                $pos += 4;
            }
        }

        return ['type' => $type, 'datasize' => $size];
    }

    public static function extractZlibData(string $data): string
    {
        $pos = 0;
        $len = strlen($data);

        while ($pos < $len) {
            $tag = self::readVarint($data, $pos);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            if ($wire === 2) {
                $length = self::readVarint($data, $pos);

                if ($field === 3) {
                    return substr($data, $pos, $length);
                }

                $pos += $length;
            } elseif ($wire === 0) {
                self::readVarint($data, $pos);
            } elseif ($wire === 1) {
                $pos += 8;
            } elseif ($wire === 5) {
                $pos += 4;
            }
        }

        return '';
    }

    /**
     * @return array{
     *     granularity: int,
     *     lat_offset: int,
     *     lon_offset: int,
     *     strings: list<string>,
     *     groups: list<array{type: string, ids: list<int>, lats: list<int>, lons: list<int>, keysVals: list<int>}>
     * }
     */
    public static function parsePrimitiveBlock(string $data): array
    {
        $result = [
            'granularity' => 100,
            'lat_offset' => 0,
            'lon_offset' => 0,
            'strings' => [],
            'groups' => [],
        ];

        $pos = 0;
        $len = strlen($data);

        while ($pos < $len) {
            $tag = self::readVarint($data, $pos);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            if ($wire === 2) {
                $length = self::readVarint($data, $pos);
                $end = $pos + $length;

                if ($field === 1) {
                    $result['strings'] = self::parseStringTable($data, $pos, $end);
                } elseif ($field === 2) {
                    $result['groups'][] = self::parsePrimitiveGroup($data, $pos, $end);
                }

                $pos = $end;
            } elseif ($wire === 0) {
                $value = self::readVarint($data, $pos);

                if ($field === 17) {
                    $result['granularity'] = $value;
                } elseif ($field === 19) {
                    $result['lat_offset'] = $value;
                } elseif ($field === 20) {
                    $result['lon_offset'] = $value;
                }
            } elseif ($wire === 1) {
                $pos += 8;
            } elseif ($wire === 5) {
                $pos += 4;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function parseStringTable(
        string $data,
        int $pos,
        int $end,
    ): array {
        $strings = [];

        while ($pos < $end) {
            $tag = self::readVarint($data, $pos);
            $wire = $tag & 0x07;

            if ($wire === 2) {
                $length = self::readVarint($data, $pos);
                $strings[] = substr($data, $pos, $length);
                $pos += $length;
            } elseif ($wire === 0) {
                self::readVarint($data, $pos);
            } elseif ($wire === 1) {
                $pos += 8;
            } elseif ($wire === 5) {
                $pos += 4;
            }
        }

        return $strings;
    }

    /**
     * @return array{type: string, ids: list<int>, lats: list<int>, lons: list<int>, keysVals: list<int>}
     */
    private static function parsePrimitiveGroup(
        string $data,
        int $pos,
        int $end,
    ): array {
        while ($pos < $end) {
            $tag = self::readVarint($data, $pos);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            if ($wire === 2) {
                $length = self::readVarint($data, $pos);
                $fieldEnd = $pos + $length;

                if ($field === 2) {
                    return self::parseDenseNodes($data, $pos, $fieldEnd);
                }

                if ($field === 3) {
                    return ['type' => 'ways', 'ids' => [], 'lats' => [], 'lons' => [], 'keysVals' => []];
                }

                if ($field === 4) {
                    return ['type' => 'relations', 'ids' => [], 'lats' => [], 'lons' => [], 'keysVals' => []];
                }

                $pos = $fieldEnd;
            } elseif ($wire === 0) {
                self::readVarint($data, $pos);
            } elseif ($wire === 1) {
                $pos += 8;
            } elseif ($wire === 5) {
                $pos += 4;
            }
        }

        return ['type' => 'empty', 'ids' => [], 'lats' => [], 'lons' => [], 'keysVals' => []];
    }

    /**
     * @return array{type: 'dense', ids: list<int>, lats: list<int>, lons: list<int>, keysVals: list<int>}
     */
    private static function parseDenseNodes(
        string $data,
        int $pos,
        int $end,
    ): array {
        $ids = [];
        $lats = [];
        $lons = [];
        $keysVals = [];

        while ($pos < $end) {
            $tag = self::readVarint($data, $pos);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            if ($wire === 2) {
                $length = self::readVarint($data, $pos);
                $fieldEnd = $pos + $length;

                if ($field === 10) {
                    $keysVals = self::readPackedInt32($data, $pos, $fieldEnd);
                } elseif ($field === 1) {
                    $ids = self::readPackedSint64($data, $pos, $fieldEnd);
                } elseif ($field === 8) {
                    $lats = self::readPackedSint64($data, $pos, $fieldEnd);
                } elseif ($field === 9) {
                    $lons = self::readPackedSint64($data, $pos, $fieldEnd);
                }

                $pos = $fieldEnd;
            } elseif ($wire === 0) {
                self::readVarint($data, $pos);
            } elseif ($wire === 1) {
                $pos += 8;
            } elseif ($wire === 5) {
                $pos += 4;
            }
        }

        return [
            'type' => 'dense',
            'ids' => $ids,
            'lats' => $lats,
            'lons' => $lons,
            'keysVals' => $keysVals,
        ];
    }

    /**
     * @return list<int>
     */
    private static function readPackedSint64(
        string $data,
        int $pos,
        int $end,
    ): array {
        $len = strlen($data);
        $result = [];

        while ($pos < $end) {
            if ($pos >= $len) {
                break;
            }

            $byte = ord($data[$pos++]);
            $value = $byte & 0x7F;

            if ($byte < 0x80) {
                $result[] = ($value >> 1) ^ -($value & 1);
                continue;
            }

            if ($pos >= $len) {
                break;
            }

            $byte = ord($data[$pos++]);
            $value |= ($byte & 0x7F) << 7;

            if ($byte < 0x80) {
                $result[] = ($value >> 1) ^ -($value & 1);
                continue;
            }

            if ($pos >= $len) {
                break;
            }

            $byte = ord($data[$pos++]);
            $value |= ($byte & 0x7F) << 14;

            if ($byte < 0x80) {
                $result[] = ($value >> 1) ^ -($value & 1);
                continue;
            }

            $shift = 21;

            do {
                if ($pos >= $len) {
                    break 2;
                }

                $byte = ord($data[$pos++]);
                $value |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while ($byte >= 0x80);

            $result[] = ($value >> 1) ^ -($value & 1);
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private static function readPackedInt32(
        string $data,
        int $pos,
        int $end,
    ): array {
        $len = strlen($data);
        $result = [];

        while ($pos < $end) {
            if ($pos >= $len) {
                break;
            }

            $byte = ord($data[$pos++]);

            if ($byte < 0x80) {
                $result[] = $byte;
                continue;
            }

            $value = $byte & 0x7F;

            if ($pos >= $len) {
                break;
            }

            $byte = ord($data[$pos++]);

            if ($byte < 0x80) {
                $result[] = $value | ($byte << 7);
                continue;
            }

            $value |= ($byte & 0x7F) << 7;
            $shift = 14;

            do {
                if ($pos >= $len) {
                    break 2;
                }

                $byte = ord($data[$pos++]);
                $value |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while ($byte >= 0x80);

            $result[] = $value;
        }

        return $result;
    }

    private static function readVarint(
        string $data,
        int &$pos,
    ): int {
        $len = strlen($data);

        if ($pos >= $len) {
            return 0;
        }

        $byte = ord($data[$pos++]);
        $value = $byte & 0x7F;

        if ($byte < 0x80) {
            return $value;
        }

        if ($pos >= $len) {
            return $value;
        }

        $byte = ord($data[$pos++]);
        $value |= ($byte & 0x7F) << 7;

        if ($byte < 0x80) {
            return $value;
        }

        if ($pos >= $len) {
            return $value;
        }

        $byte = ord($data[$pos++]);
        $value |= ($byte & 0x7F) << 14;

        if ($byte < 0x80) {
            return $value;
        }

        $shift = 21;

        do {
            if ($pos >= $len) {
                return $value;
            }

            $byte = ord($data[$pos++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte >= 0x80);

        return $value;
    }
}
