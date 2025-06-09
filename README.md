# CONTENTS OF THIS FILE

* Introduction
* Requirements
* Installation
* Configuration
* Maintainers

## INTRODUCTION

By default, the Simplenews module does not enable the user to use the
subscribe / unsubscribe functionality in a decoupled environment, for example, a
React frontend. There are multiple issues with that:

* The Core Rest API Endpoint does not trigger a confirmation mail if needed
* You need the permission 'administer simplenews subscriptions' to use the rest
  endpoint
* Subscribe / unsubscribe URL which is sent in the Mails are always pointing to
  the D8 installation

This module tries to solve these issues.

Here is the open issue in the Simplenews issue queue regarding this
https://www.drupal.org/project/simplenews/issues/3158939

## REQUIREMENTS

This module requires the following modules:

* [Simplenews](https://www.drupal.org/project/simplenews)

## INSTALLATION

* Install as you would normally install a contributed Drupal module. Visit
  https://www.drupal.org/node/1897420 for further information.

## CONFIGURATION

* You need to set the base bath to your decoupled environment (this will be
  used to replace the D8 base URL in the subscribe / unsubscribe URLs)

  - Add this to your `settings.php`:

  `$settings['decoupled_url'] = 'http://localhost:3011';`


* Here are example React Components to show a basic example using the
  functionality

  - The newsletter subscribe form component with Formik and axios

```
<Formik
  initialValues={{email: ''}}
  onSubmit={values => {
    axios.post(`${yourBackendUrl}/simplenews-decoupled/subscribe`, {
      "email": values.email,
      "newsletterId": "your_newsletter_id"
    }, {
      withCredentials: true
    })
      .then(result => console.log(result))
      .catch(error => console.log(error.response.data.message));
  }}
>
  <Form>
    <Field
      id="email"
      name="email"
      placeholder="E-Mail"
      type="email"
    />
    <button type="submit">Submit</button>
   </Form>
</Formik>
```

  - Route component of the subscribe / unsubscribe confirmation page

```
<Route
  exact path="/newsletter/confirm/:action/:sid/:newsletter_id/:timestamp/:hash"
  component={NewsletterConfirm}
/>
```

- Route component of the combined subscribe / unsubscribe confirmation page

```
<Route
  exact path="/newsletter/confirm/combined/:sid/:timestamp/:hash"
  component={NewsletterCombinedConfirm}
/>
```

```
import React, { Component } from 'react';
import PropTypes from 'prop-types';
import axios from 'axios';

class NewsletterConfirm extends Component {
  state = {
    error: false,
    loading: true,
    message: null,
    success: false
  };

  componentDidMount() {
    const {
      action,
      sid,
      newsletter_id,
      timestamp,
      hash
    } = this.props.match.params;

    axios.get(`${yourBackendUrl}/simplenews-decoupled/confirm/${action}/${sid}/${newsletter_id}/${timestamp}/${hash}`)
      .then(response => {
        this.setState({
          message: response.data.message,
          success: true
        });
      })
      .catch(error => {
        // Handle error
        this.setState({
          message: error.response.data.message,
          error: true
        });
      })
      .then(() => {
        this.setState({ loading: false });
      });
  }

  render() {
    return (
      <div>
        {this.state.loading &&
          <Loading />
        }

        <div className="container">
          <div className="row">
            <div className="col-12">
              {this.props.match.params.action === 'remove' ? (
                <h1>Confirm unsubscription</h1>
              ) : (
                <h1>Confirm subscription</h1>
              )}

              {this.state.error &&
              <div className="alert alert-danger" role="alert">
                {this.state.message}
              </div>
              }

              {this.state.success &&
              <div className="alert alert-success" role="alert">
                {this.state.message}
              </div>
              }

            </div>
          </div>
        </div>
      </div>
    );
  }
}

NewsletterConfirm.propTypes = {
  match: PropTypes.object.isRequired
};

export default NewsletterConfirm;
```

For the combined confirmation, the axios get call should be

```
axios.get(`${yourBackendUrl}/simplenews-decoupled/confirm/combined/${sid}/${timestamp}/${hash}`)
```

## MAINTAINERS

* David Baetge (daveiano) - https://www.drupal.org/u/daveiano
