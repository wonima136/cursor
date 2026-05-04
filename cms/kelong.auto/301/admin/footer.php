    </main>
    
    <script>
        // 通用JS函数
        
        // 显示/隐藏模态框
        function showModal(id) {
            document.getElementById(id).classList.add('active');
        }
        
        function hideModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        // 点击遮罩关闭模态框
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // 确认删除
        function confirmDelete(message, callback) {
            if (confirm(message || '确定要删除吗？')) {
                callback();
            }
        }
        
        // 更新滑块显示值
        function updateRangeValue(slider, display) {
            document.getElementById(display).textContent = slider.value + '%';
        }
        
        // 表单提交提示
        function showMessage(type, message) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            alert.textContent = message;
            alert.style.position = 'fixed';
            alert.style.top = '20px';
            alert.style.right = '20px';
            alert.style.zIndex = '9999';
            alert.style.minWidth = '200px';
            document.body.appendChild(alert);
            
            setTimeout(() => {
                alert.remove();
            }, 3000);
        }
    </script>
</body>
</html>

