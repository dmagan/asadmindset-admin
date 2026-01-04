import { useEffect, useRef, useState } from 'react';
import Pusher from 'pusher-js';

const PUSHER_KEY = '71815fd9e2b90f89a57b';
const PUSHER_CLUSTER = 'eu';

export const usePusher = () => {
  const pusherRef = useRef(null);
  const [isConnected, setIsConnected] = useState(false);

  useEffect(() => {
    // Enable logging in development
    if (process.env.NODE_ENV === 'development') {
      Pusher.logToConsole = true;
    }

    // Initialize Pusher
    pusherRef.current = new Pusher(PUSHER_KEY, {
      cluster: PUSHER_CLUSTER,
      forceTLS: true
    });

    pusherRef.current.connection.bind('connected', () => {
      setIsConnected(true);
      console.log('Pusher connected');
    });

    pusherRef.current.connection.bind('disconnected', () => {
      setIsConnected(false);
      console.log('Pusher disconnected');
    });

    return () => {
      if (pusherRef.current) {
        pusherRef.current.disconnect();
      }
    };
  }, []);

  const subscribe = (channelName, eventName, callback) => {
    if (!pusherRef.current) return null;

    const channel = pusherRef.current.subscribe(channelName);
    channel.bind(eventName, callback);

    return () => {
      channel.unbind(eventName, callback);
      pusherRef.current.unsubscribe(channelName);
    };
  };

  const subscribeToChannel = (channelName) => {
    if (!pusherRef.current) return null;
    return pusherRef.current.subscribe(channelName);
  };

  const unsubscribe = (channelName) => {
    if (pusherRef.current) {
      pusherRef.current.unsubscribe(channelName);
    }
  };

  return {
    pusher: pusherRef.current,
    isConnected,
    subscribe,
    subscribeToChannel,
    unsubscribe
  };
};
