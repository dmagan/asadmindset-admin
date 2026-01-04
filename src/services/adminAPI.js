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

  // Upload
  async uploadMedia(file) {
    const formData = new FormData();
    formData.append('file', file);

    const response = await fetch(`${API_URL}/upload`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${getToken()}`
      },
      body: formData
    });
    
    if (!response.ok) throw new Error('Failed to upload file');
    return response.json();
  }
};
