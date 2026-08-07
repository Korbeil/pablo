// Bulma is an importmap CSS package (see importmap.php): importing it from JS
// is what makes importmap() emit its <link rel="stylesheet">. A CSS @import
// would be resolved as a relative path instead and fail.
import 'bulma/css/bulma.min.css';

import './bootstrap.js';
import './styles/app.css';
