import { __ } from "@wordpress/i18n";
import { useContext, useState } from "react";
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";
import Input from "../Common/Input";
import DashboardScreenContext from "../Context/DashboardScreenContext";

const LicenseFlowScreen = () => {
    const {
        sbCustomizer,
        editorNotification,
        editorTopLoader,
        globalSettings,
        setDashScreen,
        allowedTiers
    } = useContext( DashboardScreenContext ) ;

    const [ licenseKey, setLicenseKey ] = useState( globalSettings.pluginSettings?.license_key || '');

    const activateLicenseKey = () => {
        const notificationsContent = {
            success : {}
        },
        successNotification = {
            type : 'success',
            icon : 'success',
            text : __( 'License Activated!', 'sb-customizer' ),
            time : 5000
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
            time : 5000
        };

        const formData = {
            action : 'sbr_activate_license',
            license_key : licenseKey
        }
        if( SbUtils.checkNotEmpty( licenseKey ) ){
            SbUtils.ajaxPost(
                sbCustomizer.ajaxHandler,
                formData,
                ( data ) => { //Call Back Function
                    if( data?.data?.license === 'valid' ){
                        notificationsContent.success = successNotification;
                        globalSettings.setPluginSettings({
                            ...globalSettings.pluginSettings,
                            license_status: data?.license_status,
                            license_key: data?.license_key
                        });
                        setDashScreen('welcome')
                        allowedTiers.setTiers( data?.pluginStatus?.tier_allowed_providers )
                    }
                    if( data?.data?.license === 'deactivated' ){
                        notificationsContent.success = deactivatedNotification;
                    }
                    if( data?.success !== true && data?.data?.license === 'invalid' ){
                        notificationsContent.success = invalidNotification;
                    }

                },
                editorTopLoader,
                editorNotification,
                notificationsContent
            )
        }
    }

    return (
        <section className='sb-full-wrapper sb-fs'>
            <section className='sb-dash-license-ctn'>

                <article className='sb-dash-license-section sb-fs'>
                    <div className='sb-dash-license-centered sb-fs'>
                        { SbUtils.printIcon( 'reviews-smash', 'sb-dash-license-logo' ) }
                    </div>
                    <div className='sb-dash-license-centered sb-fs'>
                        <strong className='sb-text-tiny'>{ __( 'Review Feed Pro by Smash Balloon', 'sb-customizer' ) }</strong>
                        <h3 className='sb-h3'>{ __( 'Activate your plugin to get started', 'sb-customizer' ) }</h3>
                    </div>
                    <div className='sb-dash-license-centered sb-fs'>
                        <div className='sb-dash-license-input-ctn sb-fs'>
                            <Input
                                type='password'
                                size='medium'
                                placeholder={ __( 'Paste license key here', 'sb-customizer' ) }
                                value={ licenseKey }
                                onChange={ ( event ) => {
                                    setLicenseKey( event.currentTarget.value )
                                } }
                            />
                            <Button
                                size='medium'
                                type='primary'
                                icon='success'
                                text={ __( 'Activate', 'sb-customizer' ) }
                                onClick={ () => {
                                    activateLicenseKey()
                                } }
                            />
                        </div>
                    </div>
                    <div className='sb-dash-license-centered sb-fs'>
                        <div className='sb-dash-license-buy-ctn sb-fs'>
                            <span className='sb-dark-text sb-text-small'>{ __( 'Don’t have a license key?', 'sb-customizer' ) }</span>
                            <a
                                className='sb-bold sb-text-small sb-link sb-flex '
                                href='https://smashballoon.com/pricing/reviews-feed/?reviews&utm_campaign=reviews-pro&utm_source=welcome&utm_medium=license&utm_content=Buy'
                                target='_blank'
                                rel='noreferrer'
                            >
                                { __( 'Buy a License', 'sb-customizer' ) }
                                {SbUtils.printIcon( 'chevron-right', 'sb-dash-license-buy-icon' , false, 8 ) }
                            </a>
                        </div>
                    </div>
                </article>

                <article className='sb-dash-license-section sb-dash-license-centered-cards sb-fs'>
                    <div className='sb-dash-license-centered sb-fs'>
                        <h4 className='sb-h4'>{ __( 'After activating your plugin, you can...', 'sb-customizer' ) }</h4>
                    </div>
                    <div className='sb-dash-license-info-cards sb-fs'>

                        <div className='sb-dash-license-card sb-fs'>
                            { SbUtils.printIcon( 'rocket' ) }
                            <strong className='sb-text-small sb-dark-text'>{ __( 'Collect New Reviews', 'sb-customizer' ) }</strong>
                            <p className='sb-text-tiny sb-dark2-text'>{ __( 'New reviews and data related to the reviews for your business are collected from external sites. Your license key can be used to access this data using Smash Balloon\'s API site.', 'sb-customizer' ) }</p>
                        </div>
                        <div className='sb-dash-license-card sb-fs'>
                            { SbUtils.printIcon( 'screen-download' ) }
                            <strong className='sb-text-small sb-dark-text'>{ __( 'Recieve Critical Updates', 'sb-customizer' ) }</strong>
                            <p className='sb-text-tiny sb-dark2-text'>{ __( 'With constant changes to WordPress and the APIs that your reviews are retrieved from, your plugin needs to stay up to date. Activating your license allows automatic updates from the Plugins page.', 'sb-customizer' ) }</p>
                        </div>
                        <div className='sb-dash-license-card sb-fs'>
                            { SbUtils.printIcon( 'support' ) }
                            <strong className='sb-text-small sb-dark-text'>{ __( 'Receive Technical Support', 'sb-customizer' ) }</strong>
                            <p className='sb-text-tiny sb-dark2-text'>{ __( 'Although we pride ourselves on creating reliable, easy-to-use products our expert support team is here to help with any questions. Activating your license key is recommended to receive efficient support.', 'sb-customizer' ) }</p>
                        </div>

                    </div>
                </article>


                <article className='sb-dash-license-section sb-fs'>
                    <div className='sb-dash-license-centered sb-fs'>
                        <img src={window.sb_customizer.assetsURL + 'sb-customizer/assets/images/support-team.jpg' } alt={ __( 'Amazing Support Team.', 'sb-customizer' ) } />
                    </div>
                    <div className='sb-dash-license-centered sb-dash-license-support-heading sb-fs'>
                        <h3 className='sb-h3 sb-light-text'>{ __( 'Having trouble using the plugin?', 'sb-customizer' ) }</h3>
                        <h3 className='sb-h3 sb-dark-text'>{ __( 'Contact our friendly support team.', 'sb-customizer' ) }</h3>
                    </div>
                    <div className='sb-dash-license-centered sb-fs'>
                        <Button
                            type='primary'
                            size='medium'
                            text={ __( 'Go to Support Form', 'sb-customizer' ) }
                            icon='chevron-right'
                            icon-position='right'
                            iconSize='8'
                            link='https://smashballoon.com/support/?reviews&utm_campaign=reviews-pro&utm_source=welcome&utm_medium=support&utm_content=Go '
                            target='_blank'
                        />
                    </div>
                </article>
            </section>
        </section>
    )
}

export default LicenseFlowScreen;