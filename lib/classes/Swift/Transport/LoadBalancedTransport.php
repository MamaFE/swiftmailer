<?php

/*
 Load balanced Transport class from Swift Mailer.
 
 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.
 
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.
 
 */

//@require 'Swift/Transport.php';
//@require 'Swift/Transport/Log.php';
//@require 'Swift/Mime/Message.php';

/**
 * Redudantly and rotationally uses several Transport implementations when sending.
 * @package Swift
 * @subpackage Transport
 * @author Chris Corbyn
 */
class Swift_Transport_LoadBalancedTransport implements Swift_Transport
{
  
  /**
   * Transports which are deemed useless.
   * @var Swift_Transport[]
   * @access private
   */
  private $_deadTransports = array();
  
  /**
   * The logger used for debug logging.
   * @var Swift_Transport_Log
   * @access private
   */
  private $_log;
  
  /**
   * The Transports which are used in rotation.
   * @var Swift_Transport[]
   * @access private
   */
  protected $_transports = array();
  
  /**
   * Creates a new LoadBalancedTransport with $log for logging.
   * @param Swift_Transport_Log $log
   */
  public function __construct(Swift_Transport_Log $log)
  {
    $this->_log = $log;
  }
  
  /**
   * Set $transports to delegate to.
   * @param Swift_Transport[] $transports
   */
  public function setTransports(array $transports)
  {
    $this->_log->addLogEntry('++ ' . count($transports) . ' Transports loaded');
    $this->_transports = $transports;
    $this->_deadTransports = array();
  }
  
  /**
   * Get $transports to delegate to.
   * @return Swift_Transport[]
   */
  public function getTransports(array $transports)
  {
    return array_merge($this->_transports, $this->_deadTransports);
  }
  
  /**
   * Test if this Transport mechanism has started.
   * @return boolean
   */
  public function isStarted()
  {
    return count($this->_transports) > 0;
  }
  
  /**
   * Start this Transport mechanism.
   */
  public function start()
  {
    $this->_transports = array_merge($this->_transports, $this->_deadTransports);
  }
  
  /**
   * Stop this Transport mechanism.
   */
  public function stop()
  {
    $this->_log->addLogEntry('++ Stopping all Transports');
    foreach ($this->_transports as $transport)
    {
      $transport->stop();
    }
  }
  
  /**
   * Send the given Message.
   * Recipient/sender data will be retreived from the Message API.
   * The return value is the number of recipients who were accepted for delivery.
   * @param Swift_Mime_Message $message
   * @return int
   */
  public function send(Swift_Mime_Message $message)
  {
    $maxTransports = count($this->_transports);
    $sent = 0;
    
    for ($i = 0; $i < $maxTransports
      && $transport = $this->_getNextTransport(); ++$i)
    {
      try
      {
        if (!$transport->isStarted())
        {
          $transport->start();
        }
        if ($sent = $transport->send($message))
        {
          break;
        }
      }
      catch (Swift_Transport_TransportException $e)
      {
        $this->_killCurrentTransport();
      }
    }
    
    if (count($this->_transports) == 0)
    {
      throw new Swift_Transport_TransportException(
        'All Transports in LoadBalancedTransport failed, or no Transports available'
        );
    }
    
    return $sent;
  }
  
  // -- Protected methods
  
  /**
   * Rotates the transport list around and returns the first instance.
   * @return Swift_Transport
   * @access private
   */
  protected function _getNextTransport()
  {
    if ($next = array_shift($this->_transports))
    {
      $this->_log->addLogEntry('++ Switched to Transport of class ' . get_class($next));
      $this->_transports[] = $next;
    }
    return $next;
  }
  
  /**
   * Tag the currently used (top of stack) transport as dead/useless.
   * @access private
   */
  protected function _killCurrentTransport()
  {
    if ($transport = array_pop($this->_transports))
    {
      try
      {
        $transport->stop();
      }
      catch (Exception $e)
      {
      }
      $this->_log->addLogEntry(
        '!! Transport of class ' . get_class($transport) . ' deemed useless; disabling'
        );
      $this->_deadTransports[] = $transport;
    }
  }
  
}
