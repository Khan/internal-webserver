<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Channel that transmits dictionaries of primitives using JSON serialization.
 * This channel is not binary safe.
 *
 * This protocol is implemented by the Phabricator Aphlict realtime notification
 * server.
 *
 * @task protocol Protocol Implementation
 */
final class PhutilJSONProtocolChannel extends PhutilProtocolChannel {

  const MODE_LENGTH = 'length';
  const MODE_OBJECT = 'object';

  /**
   * Size of the "length" frame of the protocol in bytes.
   */
  const SIZE_LENGTH = 8;

  private $mode                   = self::MODE_LENGTH;
  private $byteLengthOfNextChunk  = self::SIZE_LENGTH;
  private $buf                    = '';


/* -(  Protocol Implementation  )-------------------------------------------- */


  /**
   * Encode a message for transmission over the channel. The message should
   * be any serializable as JSON.
   *
   * Objects are transmitted as:
   *
   *   <len><json>
   *
   * ...where <len> is an 8-character, zero-padded integer written as a string.
   * For example, this is a valid message:
   *
   *   00000015{"key":"value"}
   *
   * @task protocol
   */
  protected function encodeMessage($message) {
    $message = json_encode($message);
    $len = sprintf(
      '%0'.self::SIZE_LENGTH.'.'.self::SIZE_LENGTH.'d',
      strlen($message));
    return "{$len}{$message}";
  }


  /**
   * Decode a message received from the other end of the channel. Messages are
   * decoded as associative arrays.
   *
   * @task protocol
   */
  protected function decodeStream($data) {
    $this->buf .= $data;

    $objects = array();
    while (strlen($this->buf) >= $this->byteLengthOfNextChunk) {
      switch ($this->mode) {
        case self::MODE_LENGTH:
          $len = substr($this->buf, 0, self::SIZE_LENGTH);
          $this->buf = substr($this->buf, self::SIZE_LENGTH);

          $this->mode = self::MODE_OBJECT;
          $this->byteLengthOfNextChunk = (int)$len;
          break;
        case self::MODE_OBJECT:
          $data = substr($this->buf, 0, $this->byteLengthOfNextChunk);
          $this->buf = substr($this->buf, $this->byteLengthOfNextChunk);

          $obj = json_decode($data, true);
          if (!is_array($obj)) {
            throw new Exception("Failed to decode JSON object: {$data}");
          } else {
            $objects[] = $obj;
          }

          $this->mode = self::MODE_LENGTH;
          $this->byteLengthOfNextChunk = self::SIZE_LENGTH;
          break;
      }
    }

    return $objects;
  }

}
