import { __ } from '@wordpress/i18n'
import { useContext, useState } from 'react';
import SbUtils from '../../../../Utils/SbUtils';
import Button from '../../../Common/Button';
import Input from '../../../Common/Input';
import SettingsScreenContext from '../../../Context/SettingsScreenContext';

const ManageLicenseKey = ( props ) => {
    const {
        sbCustomizer,
        editorNotification,
        editorTopLoader
    } = useContext( SettingsScreenContext) ;

    const [ licenseStatus, setLicenseStatus] = useState( SbUtils.checkNotEmpty( sbCustomizer?.pluginSettings?.license_status ) ? sbCustomizer?.pluginSettings?.license_status  : null );
    const [ licenseKey, setLicenseKey ] = useState( sbCustomizer?.pluginSettings?.license_key || '' );

    const [ testConnectionLoad, setTestConnectionLoad ] = useState( false );

    const licenseKeyAction = () => {
        const notificationsContent = {
            success : {}
        },
            successNotification = {
                type : 'success',
                icon : 'success',
                text : __( 'License Activated!', 'sb-customizer' ),
                time : 10000
            },
            invalidNotification = {
                type : 'error',
                icon : 'notice',
                text : __( 'Invalid License!', 'sb-customizer' ),
                time : 60000
            },
            deactivatedNotification = {
                type : 'success',
                icon : 'success',
                text : __( 'License Dactivated!', 'sb-customizer' ),
                time : 10000
            };

        const formData = {
           action : licenseStatus !== 'valid' ? 'sbr_activate_license' :  'sbr_deactivate_license',
           // action :  'sbr_activate_license',
            license_key : licenseKey
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data?.data?.license === 'valid' ){
                    notificationsContent.success = successNotification;
                }
                if( data?.data?.license === 'deactivated' ){
                    notificationsContent.success = deactivatedNotification;
                }
                if( data?.success !== true && data?.data?.license === 'invalid' ){
                    notificationsContent.success = invalidNotification;
                }
                setLicenseStatus( data?.data?.license );
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }


    const testConnection = () => {
        setTestConnectionLoad( true )
        const formData = {
           action : 'sbr_test_connection',
            license_key : licenseKey
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                setTestConnectionLoad( data?.success ? 'success' : 'error' )
                setTimeout(() => {
                    setTestConnectionLoad( false )
                }, 4000);
            },
            editorTopLoader,
            null,
            null
        )
    }


    return (
        <>
            <span className='sb-text-small sb-dark-text'>
                {
                    licenseStatus === null &&
                    <>
                        { __( 'Please your ', 'sb-customizer' ) }
                        <strong>Reviews Feed Pro </strong>
                        { __( 'License Key ', 'sb-customizer' ) }
                    </>
                }
                {
                    licenseStatus !== null &&
                    <>
                        { __( 'Your ', 'sb-customizer' ) }
                        <strong>Reviews Feed Pro</strong>{ __( ' License is ', 'sb-customizer' ) }
                        { licenseStatus === 'valid' && __( 'Active', 'sb-customizer' ) || ( licenseStatus === 'invalid' && __( 'Inactive', 'sb-customizer' ) ) || ( licenseStatus === 'expired' && __( 'Expired', 'sb-customizer' ) ) }!
                    </>
                }
            </span>
            <div className='sb-settings-input-ctn'>
                <div>
                    <Input
                        size='medium'
                        customClass={ `${'sb-license-key-' + licenseStatus}` }
                        type='password'
                        value={ licenseKey }
                        onChange={ ( event ) => {
                            setLicenseKey( event.currentTarget.value )
                        } }
                        trailingIcon={ licenseStatus === 'valid' ? 'success' : 'notice' }
                    />
                    <div className='sb-licensekey-actions sb-fs'>
                        <div className='sb-licensekey-actions-left'>
                            <a
                                className='sb-text-tiny sb-licensekey-manage-btn'
                                href='https://smashballoon.com/account/?reviews&utm_campaign=reviews-pro&utm_source=settings&utm_medium=license&utm_content=Manage'
                                target='_blank'
                                rel='noreferrer'
                            >
                                { __( 'Manage License', 'sb-customizer' )  }
                            </a>
                        </div>
                        <div className='sb-licensekey-actions-right'>
                            <span
                                className='sb-text-tiny sb-licensekey-test-btn'
                                onClick={ () => {
                                    if( testConnectionLoad === false ){
                                        testConnection()
                                    }
                                }}
                            >
                                {
                                    ( testConnectionLoad === true || testConnectionLoad === 'success' || testConnectionLoad === 'error' )&&
                                    <span className='sb-licensekey-test-icon' data-icon={testConnectionLoad}></span>
                                }

                                { testConnectionLoad === false && __( 'Test Connection', 'sb-customizer' )  }
                                { testConnectionLoad === 'success' && __( 'Connection successful', 'sb-customizer' )  }
                                { testConnectionLoad === 'error' && __( 'Connection Error', 'sb-customizer' )  }
                            </span>
                            <a
                                className='sb-text-tiny sb-licensekey-upgrade-btn'
                                href='https://smashballoon.com/pricing/reviews-feed/?reviews&utm_campaign=reviews-pro&utm_source=settings&utm_medium=license&utm_content=Upgrade'
                                target='_blank'
                                rel='noreferrer'
                            >
                                { __( 'Upgrade', 'sb-customizer' )  }
                            </a>
                        </div>
                    </div>
                </div>
                <Button
                    text={ licenseStatus !== 'valid' ? __( 'Activate', 'sb-customizer' ) : __( 'Deactivate', 'sb-customizer' ) }
                    type='secondary'
                    size='medium'
                    onClick={ () => {
                        licenseKeyAction()
                    } }
                />
            </div>

        </>

    );
}

export default ManageLicenseKey;