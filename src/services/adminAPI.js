const API_URL = 'https://asadmindset.com/wp-json/asadmindset/v1';

const getToken = () => localStorage.getItem('admin_token');

const headers = () => ({
  'Content-Type': 'application/json',
  'Authorization': `Bearer ${getToken()}`
});

export const adminAPI = {
  // Auth
  async login(username, password) {
    const response = await fetch(`${API_URL}/admin/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    
    const data = await response.json();
    
    if (!response.ok) {
      throw new Error(data.message || 'Login failed');
    }
    
    return data;
  },

  // Get Pusher config
  async getPusherConfig() {
    const response = await fetch(`${API_URL}/pusher-config`);
    return response.json();
  },

  // Dashboard Stats
  async getStats() {
    const response = await fetch(`${API_URL}/admin/stats`, {
      headers: headers()
    });
    
    if (!response.ok) throw new Error('Failed to fetch stats');
    return response.json();
  },

  // Conversations
  async getConversations(status = 'all') {
    const params = status !== 'all' ? `?status=${status}` : '';
    const response = await fetch(`${API_URL}/admin/conversations${params}`, {
      headers: headers()
    });
    
    if (!response.ok) throw new Error('Failed to fetch conversations');
    return response.json();
  },

  async getConversation(id) {
    const response = await fetch(`${API_URL}/admin/conversations/${id}`, {
      headers: headers()
    });
    
    if (!response.ok) throw new Error('Failed to fetch conversation');
    return response.json();
  },

  async updateConversationStatus(id, status) {
    const response = await fetch(`${API_URL}/admin/conversations/${id}/status`, {
      method: 'PUT',
      headers: headers(),
      body: JSON.stringify({ status })
    });
    
    if (!response.ok) throw new Error('Failed to update status');
    return response.json();
  },

  // Messages
  async sendMessage(conversationId, messageData) {
    const response = await fetch(`${API_URL}/admin/conversations/${conversationId}/message`, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify(messageData)
    });
    
    if (!response.ok) throw new Error('Failed to send message');
    return response.json();
  },

  async editMessage(messageId, content) {
    const response = await fetch(`${API_URL}/admin/messages/${messageId}`, {
      method: 'PUT',
      headers: headers(),
      body: JSON.stringify({ content })
    });
    
    if (!response.ok) throw new Error('Failed to edit message');
    return response.json();
  },

  async deleteMessage(messageId) {
    const response = await fetch(`${API_URL}/admin/messages/${messageId}`, {
      method: 'DELETE',
      headers: headers()
    });
    
    if (!response.ok) throw new Error('Failed to delete message');
    return response.json();
  },

  async markAsRead(conversationId) {
    const response = await fetch(`${API_URL}/admin/conversations/${conversationId}/read`, {
      method: 'POST',
      headers: headers()
    });
    
    if (!response.ok) throw new Error('Failed to mark as read');
    return response.json();
  },

  // Upload
  async uploadMedia(file) {
    const token = getToken();
    console.log('Upload - Token exists:', !!token);
    console.log('Upload - File:', file?.name, file?.type, file?.size);
    
    const formData = new FormData();
    formData.append('file', file);

    const response = await fetch(`${API_URL}/admin/upload`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`
      },
      body: formData
    });
    
    console.log('Upload - Response status:', response.status);
    
    if (!response.ok) {
      const errorText = await response.text();
      console.error('Upload error response:', errorText);
      throw new Error('Failed to upload file');
    }
    return response.json();
  },

  // Upload with progress (for large files like videos)
  async uploadMediaWithProgress(file, onProgress, xhrRef = null) {
    const token = getToken();
    
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      
      // Store xhr reference for cancel functionality
      if (xhrRef) {
        xhrRef.current = xhr;
      }
      
      const formData = new FormData();
      formData.append('file', file);

      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable) {
          const progress = Math.round((event.loaded / event.total) * 100);
          console.log('Upload progress:', progress + '%');
          onProgress(progress);
        }
      });

      xhr.addEventListener('load', () => {
        console.log('Upload complete, status:', xhr.status);
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const response = JSON.parse(xhr.responseText);
            if (response.success || response.url) {
              resolve(response);
            } else {
              reject(new Error(response.message || 'آپلود ناموفق'));
            }
          } catch (e) {
            reject(new Error('پاسخ نامعتبر از سرور'));
          }
        } else {
          try {
            const errorResponse = JSON.parse(xhr.responseText);
            reject(new Error(errorResponse.message || `خطای سرور: ${xhr.status}`));
          } catch (e) {
            reject(new Error(`خطای سرور: ${xhr.status}`));
          }
        }
      });

      xhr.addEventListener('error', () => {
        console.error('XHR error event fired');
        reject(new Error('خطای شبکه'));
      });

      xhr.addEventListener('abort', () => {
        console.log('Upload cancelled');
        reject(new Error('آپلود کنسل شد'));
      });

      xhr.addEventListener('timeout', () => {
        reject(new Error('زمان آپلود به پایان رسید'));
      });

      xhr.timeout = 300000; // 5 minutes
      xhr.open('POST', `${API_URL}/admin/upload`);
      xhr.setRequestHeader('Authorization', `Bearer ${token}`);
      xhr.send(formData);
    });
  }
};