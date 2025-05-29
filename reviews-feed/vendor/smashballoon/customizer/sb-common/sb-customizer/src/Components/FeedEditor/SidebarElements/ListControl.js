import { useState } from "react";
import SbUtils from '../../../Utils/SbUtils'
import SingleControl from './SingleControl';

const ListControl = ( { list, listInd } ) => {
    const attrList = [
        'dimmed',
        'fullspace'
    ];

    return (
        <div
            className='sb-listcontrols-ctn sb-fs'
            { ...SbUtils.getElementAttributes( list, attrList ) }
        >
            {
                list.heading &&
                <span className='sb-text-tiny sb-bold'>{ list.heading }</span>
            }
            <div className="sb-listcontrols-items sb-fs">
                {
                    list.controls.map( ( element, elementInd ) => {
                        element = {
                            ...element,
                            stacked : true,
                            layout : element.heading !== undefined ? 'half' : 'block',
                            strongheading : element.strongheading === undefined ? 'false' : element.strongheading
                        }
                        /** Render normal control*/
                        return (
                            <SingleControl
                                key={ elementInd }
                                control={ element }
                                controlInd={ elementInd }
                            />
                        );
                    })
                }
            </div>
        </div>
    )

}

export default ListControl;